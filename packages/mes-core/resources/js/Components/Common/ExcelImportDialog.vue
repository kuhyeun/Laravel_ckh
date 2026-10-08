<!-- 엑셀 일괄 등록 — 모달 본체 ( ExcelImportButton 이 연다 )
     1) [양식 받기]  2) 모드( 신규만 / 신규 + 수정 ) · 파일 선택 → [검증]  3) 오류가 없으면 [반영]
     - 검증은 저장하지 않는다. 반영은 서버가 다시 검증하고 전부 성공일 때만 한 트랜잭션으로 저장
     - 오류가 있으면 올린 파일에 '오류 사유' 열을 붙여 돌려준다 → 고쳐서 그대로 다시 올리면 된다
     - 결과는 서버의 행 단위 목록( result.rows ) — [결과 목록]이 ExcelImportResult 모달로 행마다 보여 준다 -->
<template>
    <div class="mt-3 text-sm space-y-4">
        <!-- 1. 양식 -->
        <div class="flex items-center justify-between gap-3 px-3 py-2.5 rounded border border-green-200 bg-green-50">
            <div class="text-xs text-green-800 leading-relaxed">
                <b>{{ layout.title ?? layout.name }}</b> 으로만 올릴 수 있습니다.<br>
                목록에서 내보낸 엑셀은 올릴 수 없습니다.
            </div>
            <BaseButton size="sm" variant="secondary" class="shrink-0 whitespace-nowrap" :disabled="busy" @click="getTemplate">양식 받기</BaseButton>
        </div>

        <!-- 2. 모드 · 파일 -->
        <div>
            <label class="block text-gray-500 font-medium mb-1">등록 방식</label>
            <div class="flex flex-col gap-1">
                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" class="accent-blue-600" value="create" v-model="mode" @change="result = null" />
                    <span>신규만</span>
                    <span class="text-xs text-gray-400">— 이미 있는 데이터가 있으면 오류</span>
                </label>
                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" class="accent-blue-600" value="upsert" v-model="mode" @change="result = null" />
                    <span>신규 + 수정</span>
                    <span class="text-xs text-gray-400">— 있으면 수정 ( 빈 칸은 기존 값 유지 )</span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-gray-500 font-medium mb-1">파일</label>
            <input ref="fileInput" type="file" accept=".xlsx" class="block w-full text-xs file:mr-3 file:px-3 file:py-1.5 file:rounded file:border-0 file:bg-gray-100 hover:file:bg-gray-200 file:cursor-pointer" @change="onFile" />
        </div>

        <p v-if="errorMsg" class="text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

        <!-- 3. 결과 -->
        <div v-if="result" class="rounded border px-3 py-2.5" :class="result.summary.error ? 'border-red-200 bg-red-50' : 'border-blue-200 bg-blue-50'">
            <div class="flex items-center justify-between gap-2">
                <p class="font-medium" :class="result.summary.error ? 'text-red-700' : 'text-blue-800'">
                    전체 {{ result.summary.total }}행 · 신규 {{ result.summary.create }} · 수정 {{ result.summary.update }}
                    <template v-if="result.summary.error"> · 오류 {{ result.summary.error }}</template>
                </p>
                <button type="button" class="shrink-0 text-xs underline" :class="result.summary.error ? 'text-red-700' : 'text-blue-700'" @click="openResult">결과 목록</button>
            </div>
            <template v-if="result.summary.error">
                <ul class="mt-1.5 space-y-0.5 text-xs text-red-700">
                    <li v-for="r in errorRows.slice(0, 5)" :key="r.row">{{ r.row }}행 {{ r.key }} : {{ r.messages.join(' / ') }}</li>
                    <li v-if="errorRows.length > 5" class="text-red-400">외 {{ errorRows.length - 5 }}행</li>
                </ul>
                <p class="mt-2 text-xs text-red-600">하나라도 오류가 있으면 저장하지 않습니다. 오류 표시 파일을 받아 고친 뒤 그 파일을 다시 올리세요.</p>
            </template>
            <p v-else class="mt-1 text-xs text-blue-700">오류가 없습니다. [반영]을 누르면 저장됩니다.</p>
        </div>

        <div class="flex items-center justify-end gap-2 pt-4 border-t">
            <BaseButton v-if="result?.summary.error" size="sm" variant="secondary" :disabled="busy" @click="getErrorFile">오류 표시 파일 받기</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="busy || !file" @click="validate">{{ busy && step === 'validate' ? '검증 중…' : '검증' }}</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="busy || !canCommit" @click="commit">{{ busy && step === 'commit' ? '반영 중…' : '반영' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ExcelImportResult from '@core/Components/Common/ExcelImportResult.vue';
import { downloadTemplate, readUpload, downloadErrorFile } from '@core/Utils/excelImport';
import { useModalStore } from '@core/Stores/modalStore';

const props = defineProps({
    layout:        { type: Object,   required: true },   // 업로드 양식 ( type: import )
    validateRoute: { type: String,   required: true },   // 예) config.item.import.validate
    commitRoute:   { type: String,   required: true },
    refLists:      { type: Object,   default: () => ({}) },   // 참조형 칸 드롭다운 { routing: async () => [...] }
    filename:      { type: String,   default: '일괄등록' },
    onDone:        { type: Function, default: null },   // 반영 성공 ( 결과 전달 )
});
const emit = defineEmits(['close']);

const mode      = ref('create');
const file      = ref(null);
const fileInput = ref(null);
const upload    = ref(null);   // readUpload 결과 ( 오류 표시 파일에 다시 쓴다 )
const result    = ref(null);   // 서버 결과 { ok, committed, summary, rows }
const busy      = ref(false);
const step      = ref('');
const errorMsg  = ref('');

const errorRows = computed(() => (result.value?.rows ?? []).filter(r => r.status === 'error'));
const canCommit = computed(() => !!result.value && result.value.ok && !result.value.committed);

function onFile(e) {
    file.value   = e.target.files?.[0] ?? null;
    upload.value = null;
    result.value = null;
    errorMsg.value = '';
}

async function getTemplate() {
    busy.value = true;
    try {
        await downloadTemplate(props.layout, props.refLists);
    } catch (e) {
        errorMsg.value = '양식을 만들지 못했습니다.';
    } finally {
        busy.value = false;
    }
}

function payload() {
    return {
        layout:  props.layout.id,
        version: upload.value.version,
        mode:    mode.value,
        rows:    upload.value.rows,
    };
}

async function validate() {
    busy.value = true;
    step.value = 'validate';
    errorMsg.value = '';
    result.value = null;
    try {
        upload.value = await readUpload(file.value, props.layout);
        const { data } = await window.axios.post(route(props.validateRoute), payload());
        result.value = data;
    } catch (e) {
        errorMsg.value = messageOf(e);
    } finally {
        busy.value = false;
        step.value = '';
    }
}

async function commit() {
    busy.value = true;
    step.value = 'commit';
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route(props.commitRoute), payload());
        result.value = data;
        props.onDone?.(data);
        emit('close');
    } catch (e) {
        // 검증과 반영 사이에 바뀐 것이 있으면 서버가 다시 검증한 결과( 422 + rows )가 온다
        if (e.response?.status === 422 && e.response.data?.rows) {
            result.value = e.response.data;
            errorMsg.value = '반영 직전 다시 검증했더니 오류가 생겼습니다. 아래 내용을 확인하세요.';
        } else {
            errorMsg.value = messageOf(e);
        }
    } finally {
        busy.value = false;
        step.value = '';
    }
}

const modalStore = useModalStore();

function openResult() {
    modalStore.open(
        ExcelImportResult,
        {
            layout:     props.layout,
            result:     result.value,
            upload:     upload.value,
            modalTitle: '업로드 결과',
        },
        { size: 'xl' }
    );
}

async function getErrorFile() {
    if (!upload.value || !result.value) return;
    busy.value = true;
    try {
        await downloadErrorFile(upload.value, result.value, props.filename);
    } finally {
        busy.value = false;
    }
}

function messageOf(e) {
    if (!e.response) return e.message || '처리하지 못했습니다.';
    if (e.response.status === 403) return '등록 권한이 없습니다.';
    const errs = e.response.data?.errors;
    if (errs) return Object.values(errs).flat().join('\n');
    return e.response.data?.message || '처리하지 못했습니다.';
}
</script>

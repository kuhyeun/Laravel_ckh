<!-- 첨부파일 — 화면정의 CMP-03 MesAttach
     - ref-type + ref-id 로 어떤 대상에도 붙는다 ( 서버에 등록된 ref_type 만 — AttachmentService::register )
     - ref-id 가 없으면( 저장 전 ) 첨부할 수 없다는 안내만 보인다 → 저장한 뒤 첨부
     - 올리기 · 삭제는 바로 저장된다 ( 폼의 [저장]과 무관 )
     - 권한을 판단하지 않는다 — 서버가 막는다. readonly 는 버튼을 숨기기만 한다
     - 미리보기 없음( Phase 2 ). 받기만 한다
     - emits : uploaded( 올린 개수 ) · deleted( 지운 행 ) · change( 파일 수 ) -->
<template>
    <div class="text-sm">
        <p v-if="!refId" class="px-3 py-6 text-center text-xs text-gray-400 border border-dashed rounded">
            저장한 뒤 파일을 첨부할 수 있습니다.
        </p>

        <template v-else>
            <!-- 올리기 -->
            <div
                v-if="!readonly"
                class="mb-3 flex items-center gap-2 px-3 py-2.5 rounded border border-dashed transition-colors"
                :class="dragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300'"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <BaseSelect v-model="category" :options="categoryOptions" size="sm" class="w-28 shrink-0" :disabled="busy" />
                <span class="flex-1 text-xs text-gray-400">
                    파일을 끌어다 놓거나 [파일 선택] · 파일당 {{ MAX_MB }}MB · 한 번에 {{ MAX_FILES }}개
                </span>
                <input ref="fileInput" type="file" multiple class="hidden" :accept="acceptAttr" @change="onPick" />
                <BaseButton size="sm" variant="secondary" class="shrink-0" :disabled="busy || !category" @click="fileInput?.click()">
                    {{ busy ? '올리는 중…' : '파일 선택' }}
                </BaseButton>
            </div>

            <p v-if="errorMsg" class="mb-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 목록 -->
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b text-gray-500">
                        <th class="py-1.5 px-2 text-left font-medium w-20">구분</th>
                        <th class="py-1.5 px-2 text-left font-medium">파일</th>
                        <th class="py-1.5 px-2 text-right font-medium w-20">크기</th>
                        <th class="py-1.5 px-2 text-left font-medium w-36">올린 사람 · 시각</th>
                        <th v-if="!readonly" class="w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td :colspan="readonly ? 4 : 5" class="py-4 text-center text-gray-400">불러오는 중…</td>
                    </tr>
                    <tr v-else-if="!files.length">
                        <td :colspan="readonly ? 4 : 5" class="py-4 text-center text-gray-400">첨부된 파일이 없습니다.</td>
                    </tr>
                    <tr v-for="f in files" v-else :key="f.attachment_idx" class="border-b last:border-b-0 hover:bg-gray-50">
                        <td class="py-1.5 px-2 text-gray-600">{{ nameOf('ATTACH_TYPE', f.category_code) }}</td>
                        <td class="py-1.5 px-2 break-all">
                            <a :href="route('attachment.download', f.attachment_idx)" class="text-blue-700 hover:underline">{{ f.file_name }}</a>
                        </td>
                        <td class="py-1.5 px-2 text-right text-gray-500 whitespace-nowrap">{{ sizeText(f.file_size) }}</td>
                        <td class="py-1.5 px-2 text-gray-500 whitespace-nowrap">
                            {{ f.creator?.user_name || f.creator?.user_id || '-' }} · {{ dateText(f.create_datetime) }}
                        </td>
                        <td v-if="!readonly" class="py-1.5 px-2 text-right">
                            <button type="button" class="text-red-500 hover:underline disabled:opacity-40" :disabled="busy" @click="remove(f)">삭제</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { showAlert } from '@core/Utils/message';

// 서버 AttachmentService 와 맞출 것
const MAX_MB     = 50;
const MAX_FILES  = 10;
const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'xls', 'xlsx', 'doc', 'docx', 'ppt', 'pptx', 'hwp', 'hwpx', 'txt', 'csv', 'dwg', 'dxf', 'zip'];

const props = defineProps({
    refType:  { type: String,  required: true },
    refId:    { type: Number,  default: null },    // 없으면 저장 전
    category: { type: String,  default: '' },      // 기본 구분 ( ATTACH_TYPE 코드 )
    readonly: { type: Boolean, default: false },
    accept:   { type: String,  default: '' },      // 파일 선택창 형식 ( 비우면 허용 형식 전부 )
});
const emit = defineEmits(['uploaded', 'deleted', 'change']);

const files     = ref([]);
const loading   = ref(false);
const busy      = ref(false);
const dragging  = ref(false);
const errorMsg  = ref('');
const fileInput = ref(null);

const acceptAttr = computed(() => props.accept || EXTENSIONS.map(e => '.' + e).join(','));

// ---- 구분 ----
const { options, nameOf, loading: codeLoading } = useCommonCode(['ATTACH_TYPE']);
const categoryOptions = computed(() => options('ATTACH_TYPE'));
const category = ref(props.category);
watch([categoryOptions, codeLoading], () => {
    if (!category.value && categoryOptions.value.length) category.value = categoryOptions.value[0].value;
}, { immediate: true });

// ---- 목록 ----
async function load() {
    if (!props.refId) {
        files.value = [];
        return;
    }
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('attachment.list'), {
            params: { ref_type: props.refType, ref_idx: props.refId },
        });
        files.value = data.attachments ?? [];
        emit('change', files.value.length);
    } catch (e) {
        errorMsg.value = messageOf(e, '첨부 목록을 불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
}
watch(() => [props.refType, props.refId], load, { immediate: true });

// ---- 올리기 ----
function onPick(e) {
    upload([...(e.target.files ?? [])]);
    e.target.value = '';   // 같은 파일을 다시 고를 수 있게
}

function onDrop(e) {
    dragging.value = false;
    if (props.readonly || busy.value) return;
    upload([...(e.dataTransfer?.files ?? [])]);
}

async function upload(list) {
    if (!list.length) return;
    errorMsg.value = '';

    // 서버도 다시 검사한다. 여기서는 올리기 전에 빨리 알려 주는 것만
    const problems = [];
    if (list.length > MAX_FILES) problems.push(`한 번에 ${MAX_FILES}개까지 올릴 수 있습니다.`);
    list.forEach(f => {
        const ext = (f.name.split('.').pop() ?? '').toLowerCase();
        if (!EXTENSIONS.includes(ext)) problems.push(`${f.name} : 올릴 수 없는 형식입니다.`);
        else if (f.size > MAX_MB * 1024 * 1024) problems.push(`${f.name} : ${MAX_MB}MB 를 넘습니다.`);
    });
    if (problems.length) {
        errorMsg.value = problems.join('\n');
        return;
    }

    // 하나씩 보낸다 ( 요청 크기 한도 = 파일 하나 기준. 실패한 파일만 알려 준다 )
    busy.value = true;
    const failed = [];
    let done = 0;
    for (const f of list) {
        const body = new FormData();
        body.append('ref_type', props.refType);
        body.append('ref_idx', String(props.refId));
        body.append('category_code', category.value);
        body.append('files[]', f);
        try {
            await window.axios.post(route('attachment.upload'), body);
            done += 1;
        } catch (e) {
            failed.push(`${f.name} : ${messageOf(e, '올리지 못했습니다.')}`);
        }
    }
    busy.value = false;

    if (failed.length) errorMsg.value = failed.join('\n');
    if (done) emit('uploaded', done);
    await load();
}

// ---- 삭제 ( 파일까지 지운다 ) ----
async function remove(f) {
    const result = await showAlert('첨부 삭제', `${f.file_name}\n파일을 삭제합니다. 되돌릴 수 없습니다.`, 'warning', 'confirm');
    if (!result.isConfirmed) return;

    busy.value = true;
    errorMsg.value = '';
    try {
        await window.axios.post(route('attachment.delete', f.attachment_idx));
        emit('deleted', f);
        await load();
    } catch (e) {
        errorMsg.value = messageOf(e, '삭제하지 못했습니다.');
    } finally {
        busy.value = false;
    }
}

// ---- 표시 ----
function sizeText(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

function dateText(v) {
    if (!v) return '';
    const d = new Date(v);
    if (isNaN(d)) return String(v).slice(0, 16).replace('T', ' ');
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function messageOf(e, fallback) {
    const status = e.response?.status;
    if (status === 403) return '권한이 없습니다.';
    if (status === 413) return `파일이 너무 큽니다. ( 파일당 ${MAX_MB}MB )`;
    const errs = e.response?.data?.errors;
    if (errs) return [...new Set(Object.values(errs).flat())].join('\n');
    return e.response?.data?.message || fallback;
}

defineExpose({ reload: load });
</script>

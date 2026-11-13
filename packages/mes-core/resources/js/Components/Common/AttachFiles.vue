<!-- 첨부파일 — 화면정의 CMP-03 MesAttach
     - ref-type + ref-id 로 어떤 대상에도 붙는다 ( 서버에 등록된 ref_type 만 — AttachmentService::register )
     - ref-id 가 없으면( 저장 전 ) 첨부할 수 없다는 안내만 보인다 → 저장한 뒤 첨부
       단 deferred 면( 신규 등록 창 · 11-12 ) 고른 파일을 화면에만 담아 두고 '저장할 때 올림'으로 보인다
       → 여는 쪽이 저장에 성공한 뒤 flush( 새 번호 )를 부르면 그때 올린다 · 취소하면 서버에 아무것도 남지 않는다
     - 올리기 · 삭제는 바로 저장된다 ( 폼의 [저장]과 무관 — ref-id 가 있을 때 )
     - 권한을 판단하지 않는다 — 서버가 막는다. readonly 는 버튼을 숨기기만 한다
     - 미리보기 없음( Phase 2 ). 받기만 한다
     - emits : uploaded( 올린 개수 ) · deleted( 지운 행 ) · change( 파일 수 ) -->
<template>
    <div class="text-sm">
        <p v-if="!refId && !deferred" class="px-3 py-6 text-center text-xs text-gray-400 border border-dashed rounded">
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
                <BaseSelect v-model="category" :options="categoryOptions" size="md" class="w-28 shrink-0" :disabled="busy" />
                <span class="flex-1 text-xs text-gray-400">
                    파일을 끌어다 놓거나 [파일 선택] · 파일당 {{ MAX_MB }}MB · 한 번에 {{ MAX_FILES }}개<template v-if="!refId"> · 저장할 때 함께 올립니다</template>
                </span>
                <input ref="fileInput" type="file" multiple class="hidden" :accept="acceptAttr" @change="onPick" />
                <BaseButton size="sm" variant="secondary" class="shrink-0" :disabled="busy || !category" @click="fileInput?.click()">
                    {{ busy ? '올리는 중…' : '파일 선택' }}
                </BaseButton>
            </div>

            <p v-if="errorMsg" class="mb-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 목록 -->
            <div class="overflow-x-auto">
                <!-- 크기 · 올린 사람 · 시각은 넉넉히 ( 11-12 — 글자가 잘리지 않게 · 좁으면 가로 스크롤 ) -->
                <table class="w-full min-w-[720px] table-fixed text-xs">
                    <colgroup>
                        <col class="w-24">
                        <col>
                        <col class="w-28">
                        <col class="w-64">
                        <col v-if="!readonly" class="w-12">
                    </colgroup>
                    <thead>
                        <tr class="border-b text-gray-500">
                            <th class="py-1.5 px-2 text-left font-medium">구분</th>
                            <th class="py-1.5 px-2 text-left font-medium">파일</th>
                            <th class="py-1.5 px-2 text-right font-medium">크기</th>
                            <th class="py-1.5 px-2 text-left font-medium">올린 사람 · 시각</th>
                            <th v-if="!readonly"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td :colspan="readonly ? 4 : 5" class="py-4 text-center text-gray-400">불러오는 중…</td>
                        </tr>
                        <tr v-else-if="!files.length && !pending.length">
                            <td :colspan="readonly ? 4 : 5" class="py-4 text-center text-gray-400">첨부된 파일이 없습니다.</td>
                        </tr>
                        <!-- 저장 전에 고른 파일 ( deferred ) -->
                        <tr v-for="(p, i) in pending" :key="'p' + i" class="border-b last:border-b-0 bg-amber-50/40">
                            <td class="py-1.5 px-2 text-gray-600 truncate">{{ nameOf('ATTACH_TYPE', p.category) }}</td>
                            <td class="py-1.5 px-2 break-all text-gray-700">{{ p.file.name }}</td>
                            <td class="py-1.5 px-2 text-right text-gray-500 whitespace-nowrap">{{ sizeText(p.file.size) }}</td>
                            <td class="py-1.5 px-2 text-amber-700 whitespace-nowrap">저장할 때 올림</td>
                            <td v-if="!readonly" class="py-1.5 px-2 text-right">
                                <button type="button" class="text-red-500 hover:underline disabled:opacity-40" :disabled="busy" @click="pending.splice(i, 1); emit('change', pending.length)">빼기</button>
                            </td>
                        </tr>
                        <template v-if="!loading">
                            <tr v-for="f in files" :key="f.attachment_idx" class="border-b last:border-b-0 hover:bg-gray-50">
                                <td class="py-1.5 px-2 text-gray-600 truncate" :title="nameOf('ATTACH_TYPE', f.category_code)">{{ nameOf('ATTACH_TYPE', f.category_code) }}</td>
                                <td class="py-1.5 px-2 break-all">
                                    <a :href="route('attachment.download', f.attachment_idx)" class="text-blue-700 hover:underline">{{ f.file_name }}</a>
                                </td>
                                <td class="py-1.5 px-2 text-right text-gray-500 whitespace-nowrap">{{ sizeText(f.file_size) }}</td>
                                <td class="py-1.5 px-2 text-gray-500 whitespace-nowrap truncate" :title="`${f.creator?.user_name || f.creator?.user_id || '-'} · ${dateText(f.create_datetime)}`">
                                    {{ f.creator?.user_name || f.creator?.user_id || '-' }} · {{ dateText(f.create_datetime) }}
                                </td>
                                <td v-if="!readonly" class="py-1.5 px-2 text-right">
                                    <button type="button" class="text-red-500 hover:underline disabled:opacity-40" :disabled="busy" @click="remove(f)">삭제</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
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
    deferred: { type: Boolean, default: false },   // 저장 전에도 골라 둔다 → 저장 뒤 flush( 번호 ) ( 신규 등록 창 )
});
const emit = defineEmits(['uploaded', 'deleted', 'change']);

const files     = ref([]);
const pending   = ref([]);   // 저장 전에 고른 파일 [ { file, category } ] ( deferred )
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

    // 저장 전 ( deferred ) : 화면에만 담아 둔다 — flush( 번호 ) 때 올린다
    if (!props.refId) {
        pending.value.push(...list.map(file => ({ file, category: category.value })));
        emit('change', pending.value.length);
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

// ---- 저장 뒤 올리기 ( deferred ) — 여는 쪽이 저장에 성공하면 await flush( 새 번호 ) ----
//   @return 실패 문구 목록 ( 비면 다 올림 ) — 여는 쪽이 알림에 붙인다
async function flush(refId) {
    if (!pending.value.length || !refId) return [];
    busy.value = true;
    const failed = [];
    for (const p of pending.value) {
        const body = new FormData();
        body.append('ref_type', props.refType);
        body.append('ref_idx', String(refId));
        body.append('category_code', p.category);
        body.append('files[]', p.file);
        try {
            await window.axios.post(route('attachment.upload'), body);
        } catch (e) {
            failed.push(`${p.file.name} : ${messageOf(e, '올리지 못했습니다.')}`);
        }
    }
    busy.value = false;
    pending.value = [];
    return failed;
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

defineExpose({ reload: load, flush, hasPending: () => pending.value.length > 0 });   // flush · hasPending : 신규 등록 때 골라 둔 파일 ( deferred )
</script>

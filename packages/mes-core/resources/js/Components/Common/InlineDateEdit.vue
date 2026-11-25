<!-- 그 자리 날짜 · 일시 수정 ( 공통 · 11-25 ) — 등록한 기록의 날짜 하나를 글자 자리에서 바로 고친다 ( 창을 띄우지 않는다 )
     평소 : 값 글자 + 작은 [수정]( canEdit ) / 고치는 중 : 입력 칸 + [저장] [취소] · 칸 아래 오류
     - value · mode( date | datetime ) · field( 서버로 보낼 키 ) · url · payload( 함께 보낼 값 ) · display( 평소 보일 글자 — 없으면 value )
     - warning : 있으면 [저장] 때 확인 창 ( 재고 원장 등 날짜별 결과가 바뀌는 기록 )
     - 바뀐 게 없으면 저장하지 않고 닫는다 · 성공하면 토스트( 서버 message · warnings ) + saved( 응답 )
     - 서버 : 라우트 이름 끝 editDate · DateEdit 규칙 ( 미래 · 소급 · 순서 검사 ) -->
<template>
    <span class="inline-flex items-center gap-1 align-middle" :class="editing ? 'flex-wrap' : ''">
        <template v-if="!editing">
            <span :class="textClass">{{ shown }}</span>
            <button v-if="canEdit" type="button" class="shrink-0 text-[11px] text-blue-600 hover:underline" :title="`${label || '날짜'} 고치기`" @click.stop="start">수정</button>
        </template>
        <template v-else>
            <BaseDatePicker v-model="draft" :mode="mode" size="sm" class="shrink-0" :class="pickerClass || (mode === 'datetime' ? 'w-44' : 'w-32')" @click.stop />
            <!-- [저장] [취소]는 한 묶음 — 자리가 모자라면 묶음째 아래 줄로 -->
            <span class="inline-flex items-center gap-1 whitespace-nowrap">
                <button type="button" class="shrink-0 px-1.5 h-7 rounded bg-blue-600 text-white text-[11px] hover:bg-blue-700 disabled:opacity-50" :disabled="busy" @click.stop="save">{{ busy ? '…' : '저장' }}</button>
                <button type="button" class="shrink-0 px-1.5 h-7 rounded border border-gray-300 text-gray-600 text-[11px] hover:bg-gray-50" :disabled="busy" @click.stop="cancel">취소</button>
            </span>
            <span v-if="error" class="basis-full text-[11px] text-red-500 whitespace-pre-line">{{ error }}</span>
        </template>
    </span>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';

const props = defineProps({
    value:     { type: String,  default: '' },
    mode:      { type: String,  default: 'datetime' },   // date | datetime
    field:     { type: String,  required: true },
    url:       { type: String,  required: true },
    payload:   { type: Object,  default: () => ({}) },
    label:     { type: String,  default: '' },
    warning:   { type: String,  default: '' },
    canEdit:   { type: Boolean, default: true },
    textClass: { type: String,  default: '' },
    display:   { type: String,  default: null },     // 평소 보일 글자 ( 예 : 같은 날 종료는 시각만 )
    pickerClass: { type: String, default: '' },      // 입력 칸 폭 ( 좁은 표 칸 — 예 : 'w-40' ) · 없으면 datetime w-44 / date w-32
});
const emit = defineEmits(['saved', 'editing']);   // editing( true | false ) : 고치는 중인지 — 좁은 표 칸은 그동안 열을 넓힌다
const { showFlash } = useToast();

const toModel = (v) => {
    const s = String(v ?? '').trim();
    if (!s) return '';
    return props.mode === 'datetime' ? s.slice(0, 16).replace(' ', 'T') : s.slice(0, 10);
};
const shown   = computed(() => props.display !== null ? props.display : props.mode === 'datetime' ? String(props.value ?? '').slice(0, 16) : String(props.value ?? '').slice(0, 10));
const editing = ref(false);
watch(editing, (v) => emit('editing', v));
onBeforeUnmount(() => { if (editing.value) emit('editing', false); });
const draft   = ref('');
const busy    = ref(false);
const error   = ref('');

function start() {
    draft.value = toModel(props.value);
    error.value = '';
    editing.value = true;
}
function cancel() {
    editing.value = false;
    error.value = '';
}
async function save() {
    if (!draft.value) { error.value = `${props.label || '날짜'}을( 를 ) 넣으세요.`; return; }
    if (draft.value === toModel(props.value)) { cancel(); return; }   // 바뀐 것 없음
    if (props.warning) {
        const r = await showAlert(`${props.label || '날짜'} 수정`, props.warning + '\n\n계속할까요?', 'warning', 'confirm', { confirmButtonText: '수정' });
        if (!r.isConfirmed) return;
    }
    busy.value = true;
    error.value = '';
    try {
        const v = props.mode === 'datetime' ? `${draft.value.replace('T', ' ').slice(0, 16)}:00` : draft.value.slice(0, 10);
        const { data } = await window.axios.post(props.url, { ...props.payload, [props.field]: v });
        showFlash(data?.message ?? '날짜를 고쳤습니다.', false, data?.warnings ?? []);
        editing.value = false;
        emit('saved', data);
    } catch (e) {
        const s = e.response?.status;
        error.value = s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') || (e.response.data.message ?? '고치지 못했습니다.')
            : s === 403 ? '고칠 권한이 없습니다.' : (e.response?.data?.message ?? '고치지 못했습니다.');
    } finally {
        busy.value = false;
    }
}
</script>

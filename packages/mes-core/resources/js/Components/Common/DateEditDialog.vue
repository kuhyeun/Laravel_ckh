<!-- 날짜 · 일시 수정 ( 공통 · 11-25 ) — 등록한 기록의 날짜 · 일시만 고친다 ( 수량 · LOT · 상태는 그대로 )
     열기 : modalStore.open(DateEditDialog, { modalTitle, target, fields, warning, url, payload, onDone }, { size: 'sm' })
     - fields  : [{ key, label, mode: 'date' | 'datetime', value, required = true }] — value 는 'YYYY-MM-DD' 또는 'YYYY-MM-DD HH:mm( :ss )'
     - target  : 무엇을 고치는지 한 줄 ( 예 : 'RC-261124-001 · 판재 [PNT-1]' )
     - warning : 있으면 [저장] 때 확인 창을 한 번 더 ( 재고 원장 등 날짜별 결과가 바뀌는 기록 )
     - url · payload : POST 주소와 함께 보낼 값 ( 고친 칸은 fields 의 key 로 더한다 — datetime 은 'YYYY-MM-DD HH:mm:00' )
     - 바뀐 칸이 없으면 저장하지 않고 닫는다 · 422 는 칸 아래에 · 성공하면 토스트( 서버 message · warnings ) + onDone( 응답 ) -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="target" class="mb-3 text-gray-600 truncate" :title="target">{{ target }}</p>
        <div class="space-y-3">
            <div v-for="f in fields" :key="f.key">
                <label class="block mb-1 text-xs text-gray-500">{{ f.label }}<span v-if="f.required !== false" class="text-red-500"> *</span></label>
                <BaseDatePicker v-model="values[f.key]" :mode="f.mode" size="md" class="w-full" />
                <p v-if="errors[f.key]" class="mt-0.5 text-[11px] text-red-500">{{ errors[f.key] }}</p>
            </div>
        </div>
        <p v-if="warning" class="mt-3 px-2 py-1.5 rounded bg-amber-50 text-xs text-amber-800 whitespace-pre-line">⚠ {{ warning }}</p>
        <p v-if="errorMsg" class="mt-2 text-xs text-red-500">{{ errorMsg }}</p>
        <ModalFooter>
            <BaseButton variant="secondary" @click="emit('close')">닫기</BaseButton>
            <BaseButton variant="primary" :disabled="busy" @click="save">{{ busy ? '저장 중…' : '저장' }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';

const props = defineProps({
    target:  { type: String,   default: '' },
    fields:  { type: Array,    required: true },
    warning: { type: String,   default: '' },
    url:     { type: String,   required: true },
    payload: { type: Object,   default: () => ({}) },
    onDone:  { type: Function, default: null },
});
const emit = defineEmits(['close']);
const { showFlash } = useToast();

// 화면 값 : date = 'YYYY-MM-DD' / datetime = 'YYYY-MM-DDTHH:mm' ( BaseDatePicker model )
const toModel = (f) => {
    const v = String(f.value ?? '').trim();
    if (!v) return '';
    return f.mode === 'datetime' ? v.slice(0, 16).replace(' ', 'T') : v.slice(0, 10);
};
const toServer = (f, v) => !v ? '' : (f.mode === 'datetime' ? `${v.replace('T', ' ').slice(0, 16)}:00` : v.slice(0, 10));

const values   = reactive(Object.fromEntries(props.fields.map(f => [f.key, toModel(f)])));
const errors   = reactive({});
const errorMsg = ref('');
const busy     = ref(false);

async function save() {
    Object.keys(errors).forEach(k => delete errors[k]);
    errorMsg.value = '';
    for (const f of props.fields) {
        if (f.required !== false && !values[f.key]) errors[f.key] = `${f.label}을( 를 ) 넣으세요.`;
    }
    if (Object.keys(errors).length) return;
    if (props.fields.every(f => values[f.key] === toModel(f))) { emit('close'); return; }   // 바뀐 것 없음

    if (props.warning) {
        const r = await showAlert('날짜 · 일시 수정', props.warning + '\n\n계속할까요?', 'warning', 'confirm', { confirmButtonText: '수정' });
        if (!r.isConfirmed) return;
    }

    busy.value = true;
    try {
        const body = { ...props.payload };
        props.fields.forEach(f => { body[f.key] = toServer(f, values[f.key]); });
        const { data } = await window.axios.post(props.url, body);
        showFlash(data?.message ?? '날짜를 고쳤습니다.', false, data?.warnings ?? []);
        emit('close');
        props.onDone?.(data);
    } catch (e) {
        const s = e.response?.status;
        if (s === 422) {
            const errs = e.response.data.errors ?? {};
            Object.entries(errs).forEach(([k, v]) => {
                const key = props.fields.find(f => k === f.key || k.endsWith(`.${f.key}`))?.key;
                if (key) errors[key] = v[0]; else errorMsg.value = (errorMsg.value ? errorMsg.value + ' / ' : '') + v[0];
            });
            if (!Object.keys(errs).length) errorMsg.value = e.response.data.message ?? '고치지 못했습니다.';
        } else {
            errorMsg.value = s === 403 ? '고칠 권한이 없습니다.' : (e.response?.data?.message ?? '고치지 못했습니다.');
        }
    } finally {
        busy.value = false;
    }
}
</script>

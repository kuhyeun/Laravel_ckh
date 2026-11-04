<!-- 라벨 발행 ( LBL-02 ) — 입고 상세 · LOT 입출고 이력 · 출하 상세에서 연다
     - target LOT : 바코드 번호 = LOT 번호( 고칠 수 없음 ) / SHIPMENT_LINE : 기본 = 출하번호-줄번호 · 고객 지정 번호로 바꿀 수 있음
     - 장수 기본값 = 수량 ÷ 입수( 올림 ) · 입수가 없으면 정책 기본 인쇄 매수 / 라벨당 수량은 라벨에 찍히는 값
     - [발행 · 미리보기] = 발행 기록을 만들고 미리보기를 연다 ( [인쇄] 를 눌러야 인쇄 기록 ) -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else>
            <div class="flex items-center gap-3 mb-2">
                <label class="text-xs text-gray-500">양식</label>
                <BaseSelect v-model="templateIdx" :options="templateOptions" size="sm" class="w-64" />
                <span class="text-xs text-gray-400">1D 바코드 ( Code128 )</span>
            </div>

            <div class="border rounded overflow-x-auto">
                <table class="w-full min-w-[912px] table-fixed text-xs">
                    <colgroup>
                        <col class="w-8"><col><col class="w-32"><col class="w-56"><col class="w-28"><col class="w-16"><col class="w-20"><col class="w-28">
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-2 py-1.5"><input type="checkbox" class="row-check" :checked="allOn" @change="toggleAll($event.target.checked)" /></th>
                            <th class="px-2 py-1.5 text-left">품목</th>
                            <th class="px-2 py-1.5 text-left">규격</th>
                            <th class="px-2 py-1.5 text-left">바코드 번호</th>
                            <th class="px-2 py-1.5 text-right">수량</th>
                            <th class="px-2 py-1.5 text-right">발행됨</th>
                            <th class="px-2 py-1.5">장수</th>
                            <th class="px-2 py-1.5">라벨당 수량</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="border-t" :class="r.on ? '' : 'text-gray-400'">
                            <td class="px-2 py-1.5 text-center"><input type="checkbox" class="row-check" v-model="r.on" /></td>
                            <td class="px-2 py-1.5">
                                {{ r.item_label }}
                                <p v-if="r.sub_label" class="text-gray-400">{{ r.sub_label }}</p>
                            </td>
                            <td class="px-2 py-1.5 truncate" :title="r.spec">{{ r.spec }}</td>
                            <td class="px-2 py-1.5">
                                <span v-if="isLot" class="font-semibold">{{ r.barcode_no }}</span>
                                <BaseInput v-else v-model="r.barcode_no" size="sm" class="w-full" :placeholder="r.default_no" :disabled="!r.on" />
                            </td>
                            <td class="px-2 py-1.5 text-right whitespace-nowrap">{{ r.qty === null ? '-' : qtyText(r.qty, r.spec_unit, r.spec_value) }}</td>
                            <td class="px-2 py-1.5 text-right" :class="r.issued_count ? 'text-blue-700' : 'text-gray-400'">{{ r.issued_count ? `${r.issued_count} 장` : '없음' }}</td>
                            <td class="px-2 py-1.5"><BaseInput v-model="r.label_count" type="number" min="1" max="999" size="sm" class="w-full text-right" :disabled="!r.on" /></td>
                            <td class="px-2 py-1.5"><BaseInput v-model="r.label_qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="!r.on" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400 mt-1">
                같은 번호를 장수만큼 뽑습니다.{{ isLot ? ' 바코드 번호 = LOT 번호.' : ' 비우면 출하번호-줄번호, 고객사가 정한 번호가 있으면 입력하세요.' }}
                이미 발행한 대상을 다시 발행하면 같은 번호로 하나 더 발행됩니다.
            </p>
            <p v-if="errorMsg" class="mt-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <span class="text-xs text-gray-500 mr-auto">{{ picked.length }} 건 · {{ sheetCount }} 장</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="loading || saving || !picked.length || !templateIdx" @click="issue">발행 · 미리보기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import LabelPreview from '@core/Components/Label/LabelPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { qtyText } from '@core/Components/Stock/stockCodes';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    target:   { type: String, required: true },   // LOT | SHIPMENT_LINE
    items:    { type: Array, required: true },    // [ { id, qty? } ] — LOT = lot_idx · 출하 = shipment_line_idx
    onIssued: { type: Function, default: null },  // 발행 · 인쇄 후 ( 화면 새로고침 )
});
const emit = defineEmits(['close']);

const modalStore = useModalStore();
const isLot      = props.target === 'LOT';

const loading     = ref(true);
const saving      = ref(false);
const errorMsg    = ref('');
const rows        = ref([]);
const templates   = ref([]);
const templateIdx = ref('');

const templateOptions = computed(() => templates.value.map(t => ({ value: t.label_template_idx, label: `${t.template_name} ( ${t.width_mm} × ${t.height_mm}mm )` })));
const picked     = computed(() => rows.value.filter(r => r.on));
const sheetCount = computed(() => picked.value.reduce((n, r) => n + (Number(r.label_count) || 0), 0));
const allOn      = computed(() => rows.value.length > 0 && rows.value.every(r => r.on));
function toggleAll(on) { rows.value.forEach(r => { r.on = on; }); }

onMounted(async () => {
    try {
        const { data } = await window.axios.post(route('label.draft'), { target: props.target, items: props.items });
        // 출하 번호 칸은 비워 두고 기본 번호를 placeholder 로 ( 비우면 기본 번호 )
        rows.value = (data.rows ?? []).map(r => ({ ...r, on: true, default_no: r.barcode_no, barcode_no: isLot ? r.barcode_no : '' }));
        templates.value = data.templates ?? [];
        templateIdx.value = templates.value[0]?.label_template_idx ?? '';
        if (!templates.value.length) errorMsg.value = '쓸 수 있는 라벨 양식이 없습니다.';
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        loading.value = false;
    }
});

async function issue() {
    saving.value = true; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('label.issue'), {
            target: props.target,
            label_template_idx: templateIdx.value,
            rows: picked.value.map(r => ({
                id: r.id,
                barcode_no: isLot ? null : (r.barcode_no || null),
                label_count: Number(r.label_count),
                label_qty: r.label_qty === '' || r.label_qty === null ? null : Number(r.label_qty),
            })),
        });
        props.onIssued?.();
        modalStore.open(LabelPreview, { labels: data.labels ?? [], onPrinted: props.onIssued, modalTitle: '라벨 미리보기 · 인쇄' }, { size: 'xl' });
        emit('close');
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        saving.value = false;
    }
}
</script>

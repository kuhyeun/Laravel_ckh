<!-- 라벨 한 장 — 크기는 양식( width_mm × height_mm · 기본 100 × 72 ) / 배치는 layout
     - LOT_STD      : 자재식별표 · 현품표 — 품번 · 품명 · 규격 / 재질 · 수량 · 공급처 · 공급처 LOT · 입고일
     - SHIPMENT_STD : 출하 라벨 — 고객사 · 출하번호 · 수주번호 / 고객 발주번호 · 품번 · 품명 · 규격 · 수량 · 출하일
     - 아래 = 1D 바코드( Code128 ) + 번호 글자 -->
<template>
    <div class="label-sheet" :style="{ width: `${label.width_mm}mm`, height: `${label.height_mm}mm` }">
        <div class="label-head">
            <span class="label-title">{{ label.title }}</span>
            <span class="label-date">{{ label.date }}</span>
        </div>

        <table class="label-table">
            <tbody>
                <tr v-for="r in rows" :key="r.k">
                    <th>{{ r.k }}</th>
                    <td :class="r.big ? 'big' : ''">{{ r.v }}</td>
                </tr>
            </tbody>
        </table>

        <div class="label-code">
            <Barcode :value="label.barcode_no" height="13mm" />
            <p class="label-no">{{ label.barcode_no }}</p>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Barcode from '@core/Components/Label/Barcode.vue';
import { qtyText } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    label: { type: Object, required: true },   // LabelService::sheets() 한 건
});

const join = (...v) => v.filter(x => x !== null && x !== undefined && x !== '').join(' / ');
const qty  = computed(() => props.label.label_qty === null || props.label.label_qty === undefined
    ? '' : qtyText(props.label.label_qty, props.label.spec_unit, props.label.spec_value));

const rows = computed(() => {
    const l = props.label;
    const list = l.layout === 'SHIPMENT_STD'
        ? [
            { k: '고객사',   v: l.partner_name, big: true },
            { k: '출하번호', v: `${l.shipment_no} · ${l.line_no}줄` },
            { k: '수주',     v: join(l.order_no, l.customer_po_no) },
            { k: '품번',     v: l.item_code, big: true },
            { k: '품명',     v: l.item_name },
            { k: '규격',     v: join(l.spec, l.material) },
            { k: '수량',     v: qty.value, big: true },
        ]
        : [
            { k: '품번',       v: l.item_code, big: true },
            { k: '품명',       v: l.item_name },
            { k: '규격',       v: join(l.spec, l.material) },
            { k: '수량',       v: qty.value, big: true },
            { k: '공급처',     v: l.partner_name },
            { k: '공급처 LOT', v: l.external_lot_no },
        ];
    return list;
});
</script>

<style scoped>
/* 크기는 mm — 화면 미리보기와 인쇄가 같다. 글자는 pt( 인쇄 기준 ) */
.label-sheet { box-sizing: border-box; padding: 2.5mm 3mm; background: #fff; color: #000; display: flex; flex-direction: column; overflow: hidden; font-family: 'Malgun Gothic', sans-serif; }
.label-head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 0.4mm solid #000; padding-bottom: 0.8mm; }
.label-title { font-size: 12pt; font-weight: 700; }
.label-date { font-size: 8pt; }
.label-table { width: 100%; border-collapse: collapse; margin-top: 1mm; table-layout: fixed; }
.label-table th, .label-table td { border-bottom: 0.2mm solid #000; padding: 0.5mm 1mm; font-size: 8pt; line-height: 1.2; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.label-table th { width: 17mm; font-weight: 600; }
.label-table td.big { font-size: 10pt; font-weight: 700; }
.label-code { margin-top: auto; padding: 1mm 2mm 0; }
.label-no { text-align: center; font-size: 11pt; font-weight: 700; letter-spacing: 0.3mm; margin-top: 0.5mm; }
</style>

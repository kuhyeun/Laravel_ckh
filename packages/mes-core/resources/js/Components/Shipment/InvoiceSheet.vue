<!-- 거래명세서 한 장 ( 7003 ) — docs/screens/60-출하.md §9 · 사용자 샘플 양식( 2026-10-29 )
     - A4 세로 한 장 = 위 공급받는자 보관용( 파랑 ) + 아래 공급자 보관용( 빨강 ) · 품목 11줄 고정
     - 높이 고정 : 장 280mm( A4 297 − 여백 8 × 2 ) = 한 부 135mm × 2 + 자르는 선 영역 — 칸 높이도 mm 로 고정, 내용이 넘쳐도 칸이 늘지 않는다 -->
<template>
    <div class="sheet">
        <template v-for="copy in COPIES" :key="copy.key">
            <div class="copy">
                <!-- 머리 : 거래일자 · 제목 / 공급받는자 · 공급자 — 품목 표와 칸 격자를 따로 둔다 -->
                <table class="inv" :class="copy.cls">
                    <colgroup>
                        <!-- 공급받는자 : 세로 제목 · 라벨 · 값 / 공급자 : 세로 제목 · 라벨 · 값 · 라벨 · 값 -->
                        <col style="width: 4%"><col style="width: 10%"><col style="width: 29%">
                        <col style="width: 4%"><col style="width: 10%"><col style="width: 20%"><col style="width: 8%"><col style="width: 15%">
                    </colgroup>
                    <tr class="h-title">
                        <td colspan="2" class="c lbl-sm">거 래 일 자<br><span class="val">{{ page.doc.date }}</span></td>
                        <td colspan="6" class="title"><img v-if="images.logo" :src="images.logo" alt="" class="logo">거 래 명 세 서 <span class="sub">( {{ copy.label }} )</span>
                            <span v-if="page.of > 1" class="pageno">{{ page.no }} / {{ page.of }}</span></td>
                    </tr>
                    <tr class="h-info">
                        <td rowspan="4" class="vlabel">공<br>급<br>받<br>는<br>자</td>
                        <td class="c lbl">상 호<br><span class="lbl-sm">( 법인명 )</span></td>
                        <td class="c val">{{ page.doc.receiver.name }}</td>
                        <td rowspan="4" class="vlabel zone-l">공<br>급<br>자</td>
                        <td class="c lbl">등록번호</td>
                        <td colspan="3" class="c val big">{{ page.doc.supplier.biz_no }}</td>
                    </tr>
                    <tr class="h-info">
                        <td class="c lbl">사업장<br>주 소</td>
                        <td class="c val small">{{ page.doc.receiver.address }}</td>
                        <td class="c lbl">상 호<br><span class="lbl-sm">( 법인명 )</span></td>
                        <td class="c val">{{ page.doc.supplier.name }}</td>
                        <td class="c lbl">성 명</td>
                        <td class="c val seal-cell">{{ page.doc.supplier.ceo_name }}<span class="seal">( 인 )</span><img v-if="images.seal" :src="images.seal" alt="" class="seal-img"></td>
                    </tr>
                    <tr class="h-info">
                        <td class="c lbl">전화번호</td>
                        <td class="c val">{{ page.doc.receiver.tel }}</td>
                        <td class="c lbl">사업장<br>주 소</td>
                        <td colspan="3" class="c val small">{{ page.doc.supplier.address }}</td>
                    </tr>
                    <tr class="h-info">
                        <td class="c lbl">합계금액<br><span class="lbl-sm">{{ vatLabel(page.doc.vat) }}</span></td>
                        <td class="c val big">₩{{ won(page.doc.total) }}</td>
                        <td class="c lbl">전 화</td>
                        <td class="c val small">{{ page.doc.supplier.tel }}</td>
                        <td class="c lbl">팩 스</td>
                        <td class="c val small">{{ page.doc.supplier.fax }}</td>
                    </tr>
                </table>

                <!-- 품목 -->
                <table class="inv" :class="copy.cls">
                    <colgroup>
                        <!-- No · 제품명 · 품목코드 · 수량 · 단가 · 공급가액 · 세액 -->
                        <col style="width: 6%"><col style="width: 30%"><col style="width: 17%"><col style="width: 9%">
                        <col style="width: 12%"><col style="width: 14%"><col style="width: 12%">
                    </colgroup>
                    <tr class="head">
                        <td class="c">No.</td><td class="c">제품명</td><td class="c">품목코드</td>
                        <td class="c">수량</td><td class="c">단가</td><td class="c">공급가액</td><td class="c">세액</td>
                    </tr>
                    <tr v-for="(l, i) in page.rows" :key="i" class="row">
                        <td class="c">{{ (page.no - 1) * rowsPerPage + i + 1 }}</td>
                        <td class="c">{{ l?.item_name }}</td>
                        <td class="c">{{ l?.item_code }}</td>
                        <td class="c">{{ l ? num(l.qty) : '' }}</td>
                        <td class="r">{{ l ? won(l.unit_price) : '' }}</td>
                        <td class="r">{{ l ? won(l.supply) : '' }}</td>
                        <td class="r">{{ l ? won(l.tax) : '' }}</td>
                    </tr>
                    <tr class="remark-row">
                        <td colspan="7" class="remark">비고 : {{ page.doc.remark || '' }}</td>
                    </tr>
                </table>

                <!-- 아래 : 인수자 · 납품자 · 미수금 -->
                <table class="inv" :class="copy.cls">
                    <colgroup>
                        <col style="width: 12%"><col style="width: 21%"><col style="width: 12%"><col style="width: 21%"><col style="width: 12%"><col style="width: 22%">
                    </colgroup>
                    <tr class="foot">
                        <td class="c lbl">인 수 자</td>
                        <td class="r">인</td>
                        <td class="c lbl">납 품 자</td>
                        <td class="r">인</td>
                        <td class="c lbl">미 수 금</td>
                        <td class="r">원</td>
                    </tr>
                </table>
            </div>
            <div v-if="copy.key === 'receiver'" class="cut"></div>
        </template>
    </div>
</template>

<script setup>
import { fmtNum } from '@core/Utils/format';

defineProps({
    page:        { type: Object, required: true },   // { doc, no, of, rows( 빈 줄 = null ) }
    rowsPerPage: { type: Number, default: 11 },
    images:      { type: Object, default: () => ({}) },   // { seal, logo } data URI — 사이트설정
});

const COPIES = [
    { key: 'receiver', label: '공급받는자 보관용', cls: 'blue' },
    { key: 'supplier', label: '공급자 보관용',     cls: 'red' },
];

const won = (v) => fmtNum(v, 0);
const num = (v) => fmtNum(v);
const vatLabel = (v) => ({ INCLUDED: '( VAT포함 )', EXEMPT: '( 면세 )' }[v] ?? '( VAT포함 )');
</script>

<style scoped>
/* 장 280mm = 한 부 135mm × 2 + 자르는 선 영역 10mm ( A4 297 − 여백 16 = 281 — 1mm 여유 ) */
.sheet { width: 194mm; height: 280mm; overflow: hidden; background: theme('colors.white'); display: flex; flex-direction: column;
    font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; color: #111;
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.copy { height: 135mm; overflow: hidden; }
.cut { flex: 1; display: flex; align-items: center; }
.cut::before { content: ''; flex: 1; border-top: 1px dashed theme('colors.gray.500'); }

/* 한 부 = 제목 11 + 머리 4 × 9 + 품목 머리 6 + 11 × 6 + 비고 6 + 아래 8 = 133mm */
.inv { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8.5pt; line-height: 1.15; }
/* 선 : 안쪽 1px · 바깥선과 구역 사이( 제목 | 공급받는자 | 공급자 | 품목 | 아래 ) 2px — collapse 는 굵은 선이 이긴다 */
.inv { border: 2px solid var(--c); }
.inv + .inv { margin-top: -2px; }   /* 표 사이 : 위 표 바깥선과 겹쳐 한 줄( 2px ) */
.inv tr.h-title td { border-bottom-width: 2px; }
.inv td.zone-l { border-left-width: 2px; }
.inv td { border: 1px solid var(--c); padding: 0 1.2mm; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.inv tr.h-title  { height: 11mm; }
.inv tr.h-info   { height: 9mm; }
.inv tr.head     { height: 6mm; }
.inv tr.row      { height: 6mm; }
.inv tr.remark-row { height: 6mm; }
.inv tr.foot     { height: 8mm; }
.inv .h-title td, .inv .h-info td { white-space: normal; word-break: keep-all; }

.inv.blue { --c: #1d1df0; }
.inv.red  { --c: #e11d1d; }
.inv .lbl, .inv .lbl-sm, .inv .vlabel, .inv .head td, .inv .title, .inv .remark, .inv .foot .lbl, .inv .foot .r { color: var(--c); }
.inv .lbl-sm { font-size: 7.5pt; }
.inv .c { text-align: center; }
.inv .r { text-align: right; }
.inv .val { color: #111; }
.inv .val.big { font-size: 10.5pt; }
.inv .val.small { font-size: 7.5pt; }
.inv .vlabel { text-align: center; line-height: 1.3; font-size: 8.5pt; }
.inv .title { text-align: center; font-size: 17pt; letter-spacing: 4px; position: relative; }
.inv .title .sub { font-size: 9.5pt; letter-spacing: 1px; }
.inv .title .pageno { position: absolute; right: 2mm; bottom: 1mm; font-size: 7.5pt; letter-spacing: 0; color: theme('colors.gray.500'); }
.inv .row td { color: #111; }
.inv .remark { text-align: left; }
.inv .seal-cell { position: relative; }
.inv .seal { position: absolute; right: 2mm; top: 50%; transform: translateY(-50%); color: theme('colors.gray.400'); font-size: 7.5pt; }
.inv .seal-cell { overflow: visible; }
.inv .seal-img { position: absolute; right: 0.5mm; top: 50%; transform: translateY(-50%); width: 13mm; height: 13mm; object-fit: contain; z-index: 1; }
.inv .title .logo { position: absolute; left: 2mm; top: 50%; transform: translateY(-50%); max-height: 8mm; max-width: 30mm; object-fit: contain; }
</style>

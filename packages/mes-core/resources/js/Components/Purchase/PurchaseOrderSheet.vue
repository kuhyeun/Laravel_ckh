<!-- 발주서 한 장 ( PUR-01 · PUR-02 [미리보기 · 인쇄] ) — 거래명세서( InvoiceSheet )와 같은 방식
     - A4 세로 한 장 = 한 부 ( 구매처에 보내는 것 ) · 품목 rowsPerPage( 기본 18 )줄 고정 — 많으면 장을 나눈다 ( 합계는 장마다 같은 발주 합계 )
     - 높이 고정 : 장 280mm( A4 297 − 여백 8 × 2 ) — 칸 높이도 mm 로 고정, 내용이 넘쳐도 칸이 늘지 않는다 -->
<template>
    <div class="sheet">
        <!-- 머리 : 제목 -->
        <div class="title-row">
            <img v-if="images.logo" :src="images.logo" alt="" class="logo">
            <span class="title">발 주 서</span>
            <span v-if="page.of > 1" class="pageno">{{ page.no }} / {{ page.of }}</span>
        </div>

        <!-- 수신( 구매처 ) / 발주처( 우리 ) -->
        <table class="po">
            <colgroup>
                <col style="width: 13%"><col style="width: 33%">
                <col style="width: 4%"><col style="width: 11%"><col style="width: 17%"><col style="width: 8%"><col style="width: 14%">
            </colgroup>
            <tr class="h-info">
                <td class="lbl">발주번호</td>
                <td class="val strong">{{ page.doc.order_no }}</td>
                <td rowspan="5" class="vlabel zone-l">발<br>주<br>처</td>
                <td class="lbl">등록번호</td>
                <td colspan="3" class="val c big">{{ page.doc.supplier.biz_no }}</td>
            </tr>
            <tr class="h-info">
                <td class="lbl">발 주 일</td>
                <td class="val">{{ page.doc.order_date }}</td>
                <td class="lbl">상 호</td>
                <td class="val c">{{ page.doc.supplier.name }}</td>
                <td class="lbl">성 명</td>
                <td class="val c seal-cell">{{ page.doc.supplier.ceo_name }}<span class="seal">( 인 )</span><img v-if="images.seal" :src="images.seal" alt="" class="seal-img"></td>
            </tr>
            <tr class="h-info">
                <td class="lbl">수 신</td>
                <td class="val strong">{{ page.doc.receiver.name }} <span class="honor">귀하</span></td>
                <td class="lbl">주 소</td>
                <td colspan="3" class="val small">{{ page.doc.supplier.address }}</td>
            </tr>
            <tr class="h-info">
                <td class="lbl">담 당 자</td>
                <td class="val">{{ page.doc.receiver.contact }}</td>
                <td class="lbl">전 화</td>
                <td class="val c small">{{ page.doc.supplier.tel }}</td>
                <td class="lbl">팩 스</td>
                <td class="val c small">{{ page.doc.supplier.fax }}</td>
            </tr>
            <tr class="h-info">
                <td class="lbl">전 화</td>
                <td class="val">{{ page.doc.receiver.tel }}</td>
                <td class="lbl">발 주 자</td>
                <td colspan="3" class="val c">{{ writer }}</td>
            </tr>
        </table>

        <p class="lead">아래와 같이 발주합니다.<span v-if="page.doc.order_name" class="order-name"> — {{ page.doc.order_name }}</span></p>

        <!-- 합계 -->
        <table class="po">
            <colgroup><col style="width: 20%"><col style="width: 80%"></colgroup>
            <tr class="total-row">
                <td class="lbl">합계금액<br><span class="lbl-sm">( 부가세 별도 )</span></td>
                <td class="val big total">₩ {{ won(page.doc.total) }}</td>
            </tr>
        </table>

        <!-- 품목 -->
        <table class="po lines">
            <colgroup>
                <!-- No · 품번 · 품명 / 규격 · 단위 · 수량 · 단가 · 금액 · 납기일 · 비고 -->
                <col style="width: 5%"><col style="width: 14%"><col style="width: 24%"><col style="width: 5%"><col style="width: 9%">
                <col style="width: 10%"><col style="width: 11%"><col style="width: 13%"><col style="width: 9%">
            </colgroup>
            <tr class="head">
                <td>No.</td><td>품번</td><td>품명 / 규격</td><td>단위</td><td>수량</td>
                <td>단가</td><td>금액</td><td>납기일</td><td>비고</td>
            </tr>
            <tr v-for="(l, i) in page.rows" :key="i" class="row">
                <td class="c">{{ l ? (page.no - 1) * rowsPerPage + i + 1 : '' }}</td>
                <td class="wrap"><div>{{ l?.item_code }}</div></td>
                <td class="wrap"><div>{{ l ? withSpec(l.item_name, l.item_spec) : '' }}</div></td>
                <td class="c">{{ l?.unit }}</td>
                <td class="r">{{ l ? num(l.qty) : '' }}</td>
                <td class="r">{{ l ? won(l.unit_price) : '' }}</td>
                <td class="r">{{ l ? won(l.amount) : '' }}</td>
                <td class="c">{{ l?.due_date }}</td>
                <td>{{ l?.remark }}</td>
            </tr>
            <tr class="sum">
                <td colspan="6" class="c lbl">합 계</td>
                <td class="r strong">{{ won(page.doc.total) }}</td>
                <td colspan="2"></td>
            </tr>
        </table>

        <!-- 비고 -->
        <table class="po">
            <tr class="remark-row">
                <td class="remark"><span class="lbl">비 고</span><div class="remark-text">{{ page.doc.remark || '' }}</div></td>
            </tr>
        </table>
    </div>
</template>

<script setup>
import { fmtNum, withSpec } from '@core/Utils/format';

defineProps({
    page:        { type: Object, required: true },   // { doc, no, of, rows( 빈 줄 = null ) }
    rowsPerPage: { type: Number, default: 18 },
    images:      { type: Object, default: () => ({}) },   // { seal, logo } data URI — 사이트설정
    writer:      { type: String, default: '' },          // 발주자 ( 출력한 사람 )
});

const won = (v) => fmtNum(v, 0);
const num = (v) => fmtNum(v);
</script>

<style scoped>
/* 장 280mm : 제목 16 + 머리 5 × 9 + 안내 9 + 합계 12 + 품목 머리 7 + 18 × 7.5 + 합계 7.5 + 비고 30 ≈ 262mm */
.sheet { width: 194mm; height: 280mm; overflow: hidden; background: theme('colors.white'); display: flex; flex-direction: column;
    padding: 4mm 0 0; box-sizing: border-box;
    font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; color: #111; --c: #222;
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }

.title-row { position: relative; height: 16mm; display: flex; align-items: center; justify-content: center; }
.title { font-size: 22pt; font-weight: 700; letter-spacing: 8px; border-bottom: 3px double var(--c); padding: 0 6mm 1mm; }
.logo { position: absolute; left: 0; top: 50%; transform: translateY(-50%); max-height: 10mm; max-width: 36mm; object-fit: contain; }
.pageno { position: absolute; right: 0; bottom: 1mm; font-size: 7.5pt; color: theme('colors.gray.500'); }

.po { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; line-height: 1.2; border: 2px solid var(--c); }
.po + .po { margin-top: -2px; }
.po td { border: 1px solid var(--c); padding: 0 1.5mm; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.po td.zone-l { border-left-width: 2px; }
.po tr.h-info    { height: 9mm; }
.po tr.total-row { height: 12mm; }
.po tr.head      { height: 7mm; }
.po tr.row       { height: 7.5mm; }
.po tr.sum       { height: 7.5mm; }
.po tr.remark-row { height: 30mm; }

.po .lbl { text-align: center; background: theme('colors.gray.100'); font-weight: 600; }
.po .lbl-sm { font-size: 7.5pt; font-weight: 400; }
.po .vlabel { text-align: center; line-height: 1.5; background: theme('colors.gray.100'); font-weight: 600; }
.po .val.small { font-size: 8pt; white-space: normal; word-break: keep-all; }
.po .val.big { font-size: 11pt; }
.po .strong { font-weight: 700; }
.po .honor { font-weight: 400; font-size: 8.5pt; color: theme('colors.gray.600'); }
.po .total { text-align: right; padding-right: 4mm; font-weight: 700; font-size: 13pt; }
.po .c { text-align: center; }
.po .r { text-align: right; }
.po.lines tr.head td { text-align: center; background: theme('colors.gray.100'); font-weight: 600; border-bottom-width: 2px; }
.po.lines tr.sum td { border-top-width: 2px; }
/* 품번 · 품명 : 넘치면 아랫줄로 ( 두 줄까지 — 글자를 조금 줄여 칸 높이 7.5mm 안에 · 그 너머는 … ) */
.po td.wrap { white-space: normal; line-height: 1.1; }
.po td.wrap > div { font-size: 8pt; overflow-wrap: anywhere; word-break: keep-all;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
.po .remark { vertical-align: top; padding: 1.5mm 2mm; white-space: normal; }
.po .remark .lbl { background: none; font-size: 8.5pt; }
.po .remark-text { margin-top: 1mm; white-space: pre-line; font-size: 8.5pt; line-height: 1.4; }
.po .seal-cell { position: relative; overflow: visible; }
.po .seal { position: absolute; right: 1.5mm; top: 50%; transform: translateY(-50%); color: theme('colors.gray.400'); font-size: 7.5pt; }
.po .seal-img { position: absolute; right: 0.5mm; top: 50%; transform: translateY(-50%); width: 13mm; height: 13mm; object-fit: contain; z-index: 1; }

.lead { height: 9mm; display: flex; align-items: center; font-size: 9.5pt; margin: 0; }
.lead .order-name { color: theme('colors.gray.600'); margin-left: 1mm; }
</style>

<!-- 수주 줄 자재( 수주 자재 ) 편집 창 — 수주 등록 / 수정의 줄 [자재] ( docs/대체자재-수주자재-설계.md )
     - 처음 값 = 그 품목의 활성 BOM ( 대체 자재 포함 ) · 고친 내용은 이 수주에 적용
     - 품목 BOM 에 없는 자재를 넣으면 수주 저장 때 품목 BOM 에도 등록 ( BomService::absorbIntoItemBom )
       품목 BOM 없음 → 자재 줄로( BOM 생성 ) / 원래 줄의 자재를 바꿈 → 그 줄의 대체 / 새 줄 → 줄이 하나면 그 줄의 대체 · 둘 이상이면 고른다( 대체 · 새 자재 줄 )
       이미 있는 자재 · 소요 변경 · 지운 자재는 품목 BOM 에 반영하지 않는다
     - 자재 줄 추가 · 삭제 · 소요 · 투입 공정 · 계산식 / 줄마다 대체 자재( 대체 자재 기준 소요 )
     - 투입 공정 선택지 = 이 줄의 수주 공정 ( 공정 창에서 고친 결과 — 저장 전이어도 )
     - 자재 · 대체 자재는 자재만 고른다 ( 품목 BOM 에서 온 반제품 등은 그대로 — 서버도 막는다 )
     - 규격 칸 : 고르기 목록은 그 품번으로 등록된 자재의 규격만 ( 코드관리 규격 전체가 아니라 )
       품번이 같고 규격이 다른 자재로 바꾼다 — 있는 규격이면 바로, 없는 규격이면 [적용] 할 때 새 자재로 등록( _new_spec ) ( 같은 품번 · 규격 자재를 찾고, 없으면 새 자재로 등록 — 품목관리 쓰기 권한 )
     - [적용] 은 수주 창에만 담는다 → 수주 [등록 / 수정] 때 저장 · 변경 이력 / 그 줄로 작업지시가 생기면 잠김 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="locked" class="mb-3 px-3 py-2 rounded bg-amber-50 text-xs text-amber-700">작업지시가 생성된 줄이라 자재를 바꿀 수 없습니다.</p>
        <p v-else-if="!ro" class="mb-3 text-xs text-gray-400">
            품목 BOM 을 이 수주에 맞게 고칩니다. 수주를 저장해야 반영됩니다.
            품목 BOM 에 없는 자재를 넣으면 품목 BOM 에도 등록됩니다 ( 소요 변경 · 지운 자재는 품목 BOM 에 반영하지 않음 ).
            대체 자재는 자재 출고 때 주 자재 대신 · 섞어서 낼 수 있습니다.
        </p>
        <p v-if="errorMsg" class="mb-2 text-xs text-red-500">{{ errorMsg }}</p>

        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-gray-600">자재 <span class="text-xs font-normal text-gray-400">( {{ lines.length }} )</span></span>
            <div class="flex items-center gap-2">
                <BaseButton v-if="!ro && itemIdx" size="sm" variant="secondary" :disabled="busy" @click="reload">품목 BOM 에서 다시 불러오기</BaseButton>
                <BaseButton v-if="!ro" size="sm" variant="secondary" @click="addLine">+ 자재</BaseButton>
            </div>
        </div>
        <p v-if="errors[errorPrefix]" class="mb-2 text-xs text-red-500">{{ errors[errorPrefix][0] }}</p>

        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto border rounded">
            <table class="w-full table-fixed min-w-[880px] text-sm">
                <colgroup>
                    <col>
                    <col class="w-40">
                    <col class="w-24">
                    <col class="w-44">
                    <col v-if="SHOW_CALC" class="w-28">
                    <col v-if="SHOW_CALC" class="w-24">
                    <col v-if="!ro" class="w-10">
                </colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 font-medium text-left">자재 <span class="text-red-400">*</span></th>
                        <th class="px-2 py-1.5 font-medium text-left">규격</th>
                        <th class="px-2 py-1.5 font-medium">소요 <span class="text-red-400">*</span></th>
                        <th class="px-2 py-1.5 font-medium">투입 공정</th>
                        <th v-if="SHOW_CALC" class="px-2 py-1.5 font-medium">계산식</th>
                        <th v-if="SHOW_CALC" class="px-2 py-1.5 font-medium">절단(CUT)</th>
                        <th v-if="!ro" class="px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!lines.length">
                        <td :colspan="(ro ? 4 : 5) + (SHOW_CALC ? 2 : 0)" class="px-2 py-6 text-center text-gray-400">자재가 없습니다. ( 품목에 활성 BOM 이 없으면 비어 있습니다 )</td>
                    </tr>
                    <template v-for="(l, i) in lines" :key="keyOf(l)">
                        <tr class="border-t align-top">
                            <td class="px-1.5 py-1">
                                <ItemPicker placeholder="자재 선택" v-model="l.child_item_idx" :remote="MATERIAL_ONLY" :exclude="[itemIdx]" :can-create="canCreate" :disabled="ro"
                                    :current="{ item_code: l.child_item_code, item_name: l.child_item_name, spec: l._item_spec }" @select="it => applyItem(l, it)" />
                                <p v-if="err(i, 'child_item_idx')" class="text-[11px] text-red-500">{{ err(i, 'child_item_idx') }}</p>
                                <p v-if="!SHOW_SUBS && subHasError(i)" class="mt-0.5 text-[11px] text-red-500">대체 자재에 오류가 있습니다. BOM관리에서 품목 BOM 을 확인하세요.</p>
                                <button v-if="SHOW_SUBS" type="button" class="mt-0.5 text-[11px] hover:underline" :class="subHasError(i) ? 'text-red-500' : (l.substitutes.length ? 'text-blue-700' : 'text-gray-400')" @click="toggle(l)">
                                    대체 자재 {{ l.substitutes.length }} {{ open.has(keyOf(l)) ? '▴' : '▾' }}
                                </button>
                                <!-- 품목 BOM 에 어떻게 등록되는지 ( 품목 BOM 에 없는 자재일 때 ) — 품목 BOM 줄이 둘 이상이면 고른다 -->
                                <template v-if="!ro && bomNote(l)">
                                    <p v-if="bomNote(l).text" class="mt-0.5 text-[11px] text-blue-600">{{ bomNote(l).text }}</p>
                                    <div v-else class="mt-1 flex items-center gap-1.5 text-[11px] text-blue-600">
                                        <span class="shrink-0">품목 BOM :</span>
                                        <BaseSelect :model-value="l.item_bom_target || String(itemBomLines[0].idx)" :options="bomTargetOptions" size="md" class="flex-1"
                                            @update:model-value="v => l.item_bom_target = v" />
                                    </div>
                                </template>
                            </td>
                            <td class="px-1.5 py-1">
                                <SpecPicker v-model="l.child_item_spec" size="md" :options="specOptions(l)" :new-hint="newSpecHint" :disabled="ro || !l.child_item_idx" placeholder="규격"
                                    @open="loadSpecs(l.child_item_code)" @commit="v => onSpecCommit(l, v)" />
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-full text-right" :disabled="ro" />
                                <p v-if="err(i, 'qty')" class="text-[11px] text-red-500">{{ err(i, 'qty') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseSelect v-model="l._routing_rk" :options="routingOptions" size="md" class="w-full" :disabled="ro" />
                            </td>
                            <td v-if="SHOW_CALC" class="px-1.5 py-1"><BaseInput v-model="l.calc_code" size="md" class="w-full" placeholder="예: BAR_CUT" :disabled="ro" /></td>
                            <td v-if="SHOW_CALC" class="px-1.5 py-1"><BaseInput v-model="l.ext_n01" type="number" min="0" size="md" class="w-full text-right" :disabled="ro" /></td>
                            <td v-if="!ro" class="px-1.5 text-center py-1 leading-9">
                                <button type="button" class="inline-flex items-center h-9 align-top px-1.5 text-gray-400 hover:text-red-500" title="자재 삭제" @click="lines.splice(i, 1)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="SHOW_SUBS && open.has(keyOf(l))" class="bg-gray-50/70">
                            <td :colspan="(ro ? 4 : 5) + (SHOW_CALC ? 2 : 0)" class="pl-6 pr-1.5 py-1.5">
                                <!-- 바깥 표가 min-w 를 가지므로 안쪽 표는 셀 너비를 그대로 채운다 -->
                                <table class="w-full table-fixed text-xs">
                                    <colgroup>
                                        <col class="w-6">
                                        <col>
                                        <col class="w-40">
                                        <col class="w-24">
                                        <col class="w-44">
                                        <col v-if="SHOW_CALC" class="w-28">
                                        <col v-if="SHOW_CALC" class="w-24">
                                        <col v-if="!ro" class="w-10">
                                    </colgroup>
                                    <tbody>
                                        <tr v-if="!l.substitutes.length"><td :colspan="(ro ? 5 : 6) + (SHOW_CALC ? 2 : 0)" class="py-1 text-gray-400">대체 자재가 없습니다.</td></tr>
                                        <tr v-for="(sub, j) in l.substitutes" :key="keyOf(sub)" class="align-top">
                                            <td class="text-gray-300 py-1 leading-9">└</td>
                                            <td class="pr-1.5 py-1">
                                                <ItemPicker v-model="sub.child_item_idx" :remote="MATERIAL_ONLY" :exclude="[itemIdx, l.child_item_idx]" placeholder="대체 자재 선택" :can-create="canCreate" :disabled="ro"
                                                    :current="{ item_code: sub.child_item_code, item_name: sub.child_item_name, spec: sub._item_spec }" @select="it => applyItem(sub, it)" />
                                                <p v-if="subErr(i, j, 'child_item_idx')" class="text-[11px] text-red-500">{{ subErr(i, j, 'child_item_idx') }}</p>
                                                <p v-else-if="!ro && itemBomLoaded && sub.child_item_idx && !itemBomIds.has(Number(sub.child_item_idx))" class="text-[11px] text-blue-600">품목 BOM 에도 대체 자재로 추가됩니다.</p>
                                            </td>
                                            <td class="pr-1.5 py-1">
                                                <SpecPicker v-model="sub.child_item_spec" size="md" :options="specOptions(sub)" :new-hint="newSpecHint" :disabled="ro || !sub.child_item_idx" placeholder="규격"
                                    @open="loadSpecs(sub.child_item_code)" @commit="v => onSpecCommit(sub, v)" />
                                            </td>
                                            <td class="pr-1.5 py-1">
                                                <BaseInput v-model="sub.qty" type="number" min="0" size="md" class="w-full text-right" placeholder="소요" :disabled="ro" />
                                                <p v-if="subErr(i, j, 'qty')" class="text-[11px] text-red-500">{{ subErr(i, j, 'qty') }}</p>
                                            </td>
                                            <td class="pr-1.5 text-gray-400 py-1 leading-9">투입 공정은 주 자재와 같음</td>
                                            <td v-if="SHOW_CALC" class="pr-1.5 py-1"><BaseInput v-model="sub.calc_code" size="md" class="w-full" placeholder="계산식" :disabled="ro" /></td>
                                            <td v-if="SHOW_CALC" class="pr-1.5 py-1"><BaseInput v-model="sub.ext_n01" type="number" min="0" size="md" class="w-full text-right" :disabled="ro" /></td>
                                            <td v-if="!ro" class="text-center py-1 leading-9">
                                                <button type="button" class="inline-flex items-center h-9 align-top px-1.5 py-0.5 text-gray-400 hover:text-red-500" title="대체 자재 삭제" @click="l.substitutes.splice(j, 1)">✕</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <BaseButton v-if="!ro" size="sm" variant="secondary" class="mt-1" @click="addSub(l)">+ 대체 자재</BaseButton>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ ro ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!ro" variant="primary" size="sm" :disabled="busy" @click="apply">적용</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import SpecPicker from '@core/Components/Config/SpecPicker.vue';
import { specKey, withSpec } from '@core/Utils/format';
import { showAlert } from '@core/Utils/message';
import { quickMaterial } from '@core/Components/Config/quickMaterial';
import { rkOf, linkRouting } from '@core/Components/Order/orderMaterial';

const props = defineProps({
    initial:     { type: Array,    default: () => [] },   // 지금 줄 자재 ( 복사해서 고친다 — [취소] 하면 그대로 )
    routingRows: { type: Array,    default: () => [] },   // 이 줄의 수주 공정 ( 화면 상태 ) — 투입 공정 선택지
    processes:   { type: Array,    default: () => [] },
    canCreate:   { type: Boolean,  default: false },          // 자재 고르기의 [+ 새 자재 등록] ( 품목관리 쓰기 권한 )
    itemIdx:     { type: [Number, String], default: null },   // 만드는 제품 ( 자기 자신 제외 · 다시 불러오기 )
    readonly:    { type: Boolean,  default: false },
    locked:      { type: Boolean,  default: false },
    errors:      { type: Object,   default: () => ({}) },
    errorPrefix: { type: String,   default: 'material_lines' },
    onApply:     { type: Function, default: null },
});
const emit = defineEmits(['close']);

const ro = computed(() => props.readonly || props.locked);
// _item_spec : 지금 고른 품목의 규격 ( 규격 칸을 바꾸면 이것과 견줘 품목을 바꾼다 )
const withItemSpec = (r) => ({ ...r, _item_spec: r._item_spec ?? r.child_item_spec ?? '' });
const clone = (rows) => rows.map(l => ({ ...withItemSpec(l), substitutes: (l.substitutes ?? []).map(withItemSpec) }));
// 계산식 · 절단(CUT) 칸 : 지금은 숨김 ( 값은 그대로 오가므로 저장해도 지워지지 않는다 — 출고 계산은 비어 있으면 품목 → 정책 → 개당 × 지시 )
//   다시 쓰려면 true ( 계산식은 자유 입력 대신 등록된 식에서 고르기로 바꿀 것 )
const SHOW_CALC = false;
// 대체 자재 칸( ▾ 대체 자재 n ) : 숨김 — 자재를 바꾸면 품목 BOM 에 대체 자재로 등록되므로 같은 일을 두 곳에서 하면 헷갈린다
//   값은 그대로 오간다 ( 품목 BOM 에서 온 대체 자재는 이 수주 자재에 복사되어 자재 출고 때 섞어 낼 수 있다 ) · 다시 쓰려면 true
const SHOW_SUBS = false;
const MATERIAL_ONLY = { types: ['MATERIAL'] };   // 자재 고르기 : 자재만 ( 서버에서 30개씩 )
const lines    = reactive(clone(props.initial));
const busy     = ref(false);
const errorMsg = ref('');

// 화면 열쇠 · 펼침 ( 대체 자재 오류가 있는 줄은 처음부터 펼친다 )
let seq = 0;
const keys = new WeakMap();
const keyOf = (o) => { if (!keys.has(o)) keys.set(o, `k${++seq}`); return keys.get(o); };
const open = reactive(new Set());
const toggle = (l) => open.has(keyOf(l)) ? open.delete(keyOf(l)) : open.add(keyOf(l));

const err        = (i, f) => props.errors[`${props.errorPrefix}.${i}.${f}`]?.[0] ?? '';
const subErr     = (i, j, f) => props.errors[`${props.errorPrefix}.${i}.substitutes.${j}.${f}`]?.[0] ?? '';
const subHasError = (i) => Object.keys(props.errors).some(k => k.startsWith(`${props.errorPrefix}.${i}.substitutes.`));
lines.forEach((l, i) => { if (subHasError(i)) open.add(keyOf(l)); });

// 선택지
const procName = (idx) => props.processes.find(p => Number(p.process_idx) === Number(idx))?.process_name ?? '';
const routingOptions = computed(() => [
    { value: '', label: '( 없음 · 첫 공정 )' },
    ...props.routingRows.map((r, i) => ({ value: rkOf(r), label: `${i + 1}. ${procName(r.process_idx) || r.process_name || ''}` })),
]);
// ---- 품목 BOM ( 지금 활성 ) — 품목 BOM 에 없는 자재가 어디로 등록되는지 보여 주려고 ----
const itemBomLines  = ref([]);   // [{ idx: 품목 BOM 줄 IDX, child_item_idx, label, subs: [자재 IDX] }]
const itemBomLoaded = ref(false);
const itemBomIds    = computed(() => new Set(itemBomLines.value.flatMap(l => [l.child_item_idx, ...l.subs])));
async function loadItemBom() {
    if (!props.itemIdx) return;
    try {
        const { data } = await window.axios.get(route('order.sales.itemBom', props.itemIdx));
        itemBomLines.value = (data.lines ?? []).map(l => ({
            idx: l.source_bom_line_idx, child_item_idx: Number(l.child_item_idx),
            label: withSpec(`${l.child_item_code} ${l.child_item_name}`, l.child_item_spec),
            subs: (l.substitutes ?? []).map(x => Number(x.child_item_idx)),
        }));
        itemBomLoaded.value = true;
    } catch (e) { /* 못 불러오면 안내만 안 보인다 — 서버는 그대로 등록 */ }
}
onMounted(loadItemBom);
// 자재 줄 → 품목 BOM 등록 안내 ( null = 품목 BOM 에 이미 있음 · 아직 모름 ) / { text } / { select: true } ( 줄이 둘 이상 — 고른다 )
function bomNote(l) {
    if (!itemBomLoaded.value || !l.child_item_idx || itemBomIds.value.has(Number(l.child_item_idx))) return null;
    const rows = itemBomLines.value;
    if (!rows.length) return { text: '품목 BOM 이 없어 수주를 저장하면 품목 BOM 자재 줄로 등록됩니다.' };
    const src = l.source_bom_line_idx ? rows.find(x => Number(x.idx) === Number(l.source_bom_line_idx)) : null;
    if (src) return { text: `품목 BOM 에 ${src.label} 의 대체 자재로 추가됩니다.` };
    if (rows.length === 1) return { text: `품목 BOM 에 주 자재 ${rows[0].label} 의 대체 자재로 추가됩니다.` };
    return { select: true };
}
const bomTargetOptions = computed(() => [
    ...itemBomLines.value.map(x => ({ value: String(x.idx), label: `${x.label} 의 대체 자재` })),
    { value: 'NEW_LINE', label: '새 자재 줄로 추가 ( BOM 구성 변경 · 승인 풀림 )' },
]);

// 규격 칸 고르기 목록 : 품번 → 그 품번으로 등록된 자재의 규격들 ( 목록을 열 때 한 번 불러온다 )
const specsByCode = reactive({});
const newSpecHint = computed(() => props.canCreate ? '이 품번에 없는 규격입니다. [적용]하면 새 자재로 등록합니다.' : '이 품번에 없는 규격입니다.');
async function loadSpecs(code) {
    if (!code || specsByCode[code]) return;
    specsByCode[code] = [];
    try {
        const { data } = await window.axios.get(route('common.items.search'), { params: { code, types: ['MATERIAL'], limit: 50 } });
        specsByCode[code] = [...new Set((data.rows ?? []).map(r => r.spec).filter(Boolean))];
    } catch (e) {
        delete specsByCode[code];   // 다음에 다시
    }
}
// 그 줄의 규격 목록 = 불러온 그 품번의 규격 + 지금 고른 자재의 규격 ( 아직 안 불러왔어도 지금 값은 "있는 규격" )
function specOptions(row) {
    const list = specsByCode[row.child_item_code] ?? [];
    const cur  = row._item_spec;
    return cur && !list.some(x => specKey(x) === specKey(cur)) ? [cur, ...list] : list;
}
function rememberSpec(it) {
    if (it?.spec && specsByCode[it.item_code] && !specsByCode[it.item_code].some(x => specKey(x) === specKey(it.spec))) {
        specsByCode[it.item_code] = [...specsByCode[it.item_code], it.spec];
    }
}

// 품목을 고르면( 고른 줄 ) 품번 · 품명 · 규격 · 소분류 · 단위를 줄에 ( 규격 칸이 쓴다 )
function applyItem(row, it) {
    if (!it) return;
    Object.assign(row, {
        child_item_idx: it.item_idx, child_item_code: it.item_code, child_item_name: it.item_name,
        child_item_spec: it.spec ?? '', _item_spec: it.spec ?? '', _new_spec: null, child_item_kind: it.item_kind, child_item_unit: it.spec_unit,
    });
}

// 규격을 정하면 : 같은 품번 · 그 규격의 자재로 바꾼다
//   있으면 바로 그 자재로 / 없으면 _new_spec 에 적어 두고 [적용] 할 때 새 자재로 등록 ( 품목관리 쓰기 권한 — 취소하면 아무것도 안 생긴다 )
async function onSpecCommit(row, spec) {
    if (busy.value || !row.child_item_idx || !row.child_item_code) return;   // Enter 뒤 blur 등 겹친 확정은 한 번만
    if (specKey(spec) === specKey(row._item_spec)) { row._new_spec = null; return; }
    errorMsg.value = '';
    busy.value = true;
    try {
        const { data } = await window.axios.get(route('common.items.search'), { params: { code: row.child_item_code, types: ['MATERIAL'], limit: 50 } });
        const it = (data.rows ?? []).find(r => specKey(r.spec) === specKey(spec));
        if (it) {
            applyItem(row, it);
            return;
        }
        if (!props.canCreate) throw { message: `${row.child_item_code} · 규격 ${spec || '( 없음 )'} 자재가 없습니다. 품목관리에서 먼저 등록하세요.` };
        row._new_spec = String(spec ?? '').trim();   // [적용] 할 때 등록
    } catch (e) {
        row.child_item_spec = row._item_spec;   // 되돌림
        const s = e.response?.status;
        errorMsg.value = e.message && !e.response ? e.message
            : s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ')
            : s === 403 ? '규격이 다른 자재를 등록할 권한이 없습니다. ( 품목관리 쓰기 )' : '규격을 바꾸지 못했습니다.';
    } finally {
        busy.value = false;
    }
}

function addLine() {
    lines.push({ bom_line_idx: null, source_bom_line_idx: null, child_item_idx: '', child_item_spec: '', _item_spec: '', qty: 1, _routing_rk: '', calc_code: '', ext_n01: '', remark: '', substitutes: [] });
}
function addSub(l) {
    l.substitutes.push({ child_item_idx: '', child_item_spec: '', _item_spec: '', qty: l.qty || 1, calc_code: '', ext_n01: '', remark: '' });
    open.add(keyOf(l));
}

async function reload() {
    const r = await showAlert('품목 BOM 에서 다시 불러오기', '지금 자재를 지우고 품목의 활성 BOM 을 다시 불러옵니다. 계속할까요?', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('order.sales.itemBom', props.itemIdx));
        lines.splice(0, lines.length, ...linkRouting(data.lines ?? [], props.routingRows));
        loadItemBom();
    } catch (e) {
        errorMsg.value = '품목 BOM 을 불러오지 못했습니다.';
    } finally {
        busy.value = false;
    }
}

// [적용] : 새 규격 줄( _new_spec )을 먼저 새 자재로 등록하고 담는다 — 하나라도 못 하면 창을 닫지 않는다
async function apply() {
    const pending = lines.flatMap(l => [l, ...l.substitutes]).filter(r => r._new_spec !== null && r._new_spec !== undefined);
    if (pending.length) {
        busy.value = true;
        errorMsg.value = '';
        try {
            for (const row of pending) {
                // 같은 품번 · 규격의 미사용 자재가 있으면 다시 사용으로 바꿔 쓸지 묻는다 ( 취소하면 멈춤 )
                const data = await quickMaterial({
                    item_code: row.child_item_code, item_name: row.child_item_name, spec: row._new_spec || null,
                    item_kind: row.child_item_kind || 'RAW', spec_unit: row.child_item_unit || 'EA',
                });
                if (data.cancelled) {
                    throw { message: `${row.child_item_code} · 규격 ${row._new_spec} 은 미사용 자재로 있습니다. 다시 사용으로 바꾸거나 다른 규격을 쓰세요.` };
                }
                applyItem(row, data.item);
                rememberSpec(data.item);
            }
        } catch (e) {
            const s = e.response?.status;
            errorMsg.value = e.message && !e.response ? e.message
                : s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ')
                : s === 403 ? '새 자재를 등록할 권한이 없습니다. ( 품목관리 쓰기 )' : '새 자재를 등록하지 못했습니다.';
            return;
        } finally {
            busy.value = false;
        }
    }
    props.onApply?.(clone(lines));
    emit('close');
}
</script>

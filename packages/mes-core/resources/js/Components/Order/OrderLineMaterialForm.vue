<!-- 수주 줄 자재( 수주 자재 ) 편집 창 — 수주 등록 / 수정의 줄 [자재] ( docs/대체자재-수주자재-설계.md )
     - 처음 값 = 그 품목의 활성 BOM ( 대체 자재 포함 ) · 이 수주에만 적용 ( 품목 BOM 은 바뀌지 않는다 )
     - 자재 줄 추가 · 삭제 · 소요 · 투입 공정 · 계산식 / 줄마다 대체 자재( 대체 자재 기준 소요 )
     - 투입 공정 선택지 = 이 줄의 수주 공정 ( 공정 창에서 고친 결과 — 저장 전이어도 )
     - [적용] 은 수주 창에만 담는다 → 수주 [등록 / 수정] 때 저장 · 변경 이력 / 그 줄로 작업지시가 생기면 잠김 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="locked" class="mb-3 px-3 py-2 rounded bg-amber-50 text-xs text-amber-700">작업지시가 생성된 줄이라 자재를 바꿀 수 없습니다.</p>
        <p v-else-if="!ro" class="mb-3 text-xs text-gray-400">
            품목 BOM 을 이 수주에 맞게 고칩니다. BOM관리의 품목 BOM 은 바뀌지 않습니다. 수주를 저장해야 반영됩니다.
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

        <div class="flex-1 min-h-0 overflow-y-auto border rounded">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 font-medium text-left">자재 <span class="text-red-400">*</span></th>
                        <th class="px-2 py-1.5 w-24 font-medium">소요 <span class="text-red-400">*</span></th>
                        <th class="px-2 py-1.5 w-44 font-medium">투입 공정</th>
                        <th class="px-2 py-1.5 w-28 font-medium">계산식</th>
                        <th class="px-2 py-1.5 w-24 font-medium">절단(CUT)</th>
                        <th v-if="!ro" class="px-2 py-1.5 w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!lines.length">
                        <td :colspan="ro ? 5 : 6" class="px-2 py-6 text-center text-gray-400">자재가 없습니다. ( 품목에 활성 BOM 이 없으면 비어 있습니다 )</td>
                    </tr>
                    <template v-for="(l, i) in lines" :key="keyOf(l)">
                        <tr class="border-t align-top">
                            <td class="px-1.5 py-1">
                                <ItemPicker placeholder="자재 선택" v-model="l.child_item_idx" :options="materials" :exclude="[itemIdx]" :can-create="canCreate" :disabled="ro"
                                    :current="{ item_code: l.child_item_code, item_name: l.child_item_name, spec: l.child_item_spec }" @created="m => materials.push(m)" />
                                <p v-if="err(i, 'child_item_idx')" class="text-[11px] text-red-500">{{ err(i, 'child_item_idx') }}</p>
                                <button type="button" class="mt-0.5 text-[11px] hover:underline" :class="subHasError(i) ? 'text-red-500' : (l.substitutes.length ? 'text-blue-700' : 'text-gray-400')" @click="toggle(l)">
                                    대체 자재 {{ l.substitutes.length }} {{ open.has(keyOf(l)) ? '▴' : '▾' }}
                                </button>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="ro" />
                                <p v-if="err(i, 'qty')" class="text-[11px] text-red-500">{{ err(i, 'qty') }}</p>
                            </td>
                            <td class="px-1.5 py-1">
                                <BaseSelect v-model="l._routing_rk" :options="routingOptions" size="sm" class="w-full" :disabled="ro" />
                            </td>
                            <td class="px-1.5 py-1"><BaseInput v-model="l.calc_code" size="sm" class="w-full" placeholder="예: BAR_CUT" :disabled="ro" /></td>
                            <td class="px-1.5 py-1"><BaseInput v-model="l.ext_n01" type="number" min="0" size="sm" class="w-full text-right" :disabled="ro" /></td>
                            <td v-if="!ro" class="px-1.5 py-1 text-center">
                                <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="자재 삭제" @click="lines.splice(i, 1)">✕</button>
                            </td>
                        </tr>
                        <tr v-if="open.has(keyOf(l))" class="bg-gray-50/70">
                            <td :colspan="ro ? 5 : 6" class="pl-6 pr-1.5 py-1.5">
                                <table class="w-full text-xs">
                                    <tbody>
                                        <tr v-if="!l.substitutes.length"><td class="py-1 text-gray-400">대체 자재가 없습니다.</td></tr>
                                        <tr v-for="(sub, j) in l.substitutes" :key="keyOf(sub)" class="align-top">
                                            <td class="w-6 py-1 text-gray-300">└</td>
                                            <td class="pr-1.5 py-1">
                                                <ItemPicker v-model="sub.child_item_idx" :options="materials" :exclude="[itemIdx, l.child_item_idx]" placeholder="대체 자재 선택" :can-create="canCreate" :disabled="ro"
                                                    :current="{ item_code: sub.child_item_code, item_name: sub.child_item_name, spec: sub.child_item_spec }" @created="m => materials.push(m)" />
                                                <p v-if="subErr(i, j, 'child_item_idx')" class="text-[11px] text-red-500">{{ subErr(i, j, 'child_item_idx') }}</p>
                                            </td>
                                            <td class="w-24 pr-1.5 py-1">
                                                <BaseInput v-model="sub.qty" type="number" min="0" size="sm" class="w-full text-right" placeholder="소요" :disabled="ro" />
                                                <p v-if="subErr(i, j, 'qty')" class="text-[11px] text-red-500">{{ subErr(i, j, 'qty') }}</p>
                                            </td>
                                            <td class="w-44 pr-1.5 py-1 text-gray-400">투입 공정은 주 자재와 같음</td>
                                            <td class="w-28 pr-1.5 py-1"><BaseInput v-model="sub.calc_code" size="sm" class="w-full" placeholder="계산식" :disabled="ro" /></td>
                                            <td class="w-24 pr-1.5 py-1"><BaseInput v-model="sub.ext_n01" type="number" min="0" size="sm" class="w-full text-right" :disabled="ro" /></td>
                                            <td v-if="!ro" class="w-10 py-1 text-center">
                                                <button type="button" class="px-1.5 py-0.5 text-gray-400 hover:text-red-500" title="대체 자재 삭제" @click="l.substitutes.splice(j, 1)">✕</button>
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
import { reactive, ref, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { showAlert } from '@core/Utils/message';
import { rkOf, linkRouting } from '@core/Components/Order/orderMaterial';

const props = defineProps({
    initial:     { type: Array,    default: () => [] },   // 지금 줄 자재 ( 복사해서 고친다 — [취소] 하면 그대로 )
    routingRows: { type: Array,    default: () => [] },   // 이 줄의 수주 공정 ( 화면 상태 ) — 투입 공정 선택지
    processes:   { type: Array,    default: () => [] },
    materials:   { type: Array,    default: () => [] },   // 자재 · 대체 자재 선택지 ( 새로 등록한 자재는 여기에 더한다 — 수주 창과 같은 배열 )
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
const clone = (rows) => rows.map(l => ({ ...l, substitutes: (l.substitutes ?? []).map(s => ({ ...s })) }));
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
function addLine() {
    lines.push({ bom_line_idx: null, source_bom_line_idx: null, child_item_idx: '', qty: 1, _routing_rk: '', calc_code: '', ext_n01: '', remark: '', substitutes: [] });
}
function addSub(l) {
    l.substitutes.push({ child_item_idx: '', qty: l.qty || 1, calc_code: '', ext_n01: '', remark: '' });
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
    } catch (e) {
        errorMsg.value = '품목 BOM 을 불러오지 못했습니다.';
    } finally {
        busy.value = false;
    }
}

function apply() {
    props.onApply?.(clone(lines));
    emit('close');
}
</script>

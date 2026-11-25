<!-- 문서 양식 ( 기준정보관리 > 문서 양식 · 11-18 ) — A4 문서( 발주서 · 출하지시서 )의 배치를 만든다
     - 좌 : 양식 목록 ( 문서 종류별 · 기본 양식 표시 ) / 가운데 : 종이 미리 보기( 예시 문서 · 인쇄와 같은 부품 DocSheet ) / 우 : 고른 영역의 설정
     - 양식 = 머리 영역( 격자 ) + 품목 표( 열 목록 ) + 아래 영역( 격자 ) — 종이에서 영역을 누르거나 오른쪽 위 단추로 고른다
       머리 · 아래 : 칸을 눌러 고르고( Shift + 클릭 = 여러 칸 ) [합치기] · [나누기] — 칸마다 항목 · 글자 · 그림( 로고 / 직인 )과 글자 모양
       품목 표    : 열을 더하고 빼고 순서를 바꾼다 ( 머리글 · 너비 · 정렬 · 줄바꿈 ) · 한 장 줄 수 · 줄 높이 · 합계 줄
                    줄바꿈( 11-24 ) : 칸 폭을 넘으면 아랫줄로 → 그 행만 높아지고 한 장에 들어가는 줄이 줄어든다 · 넘치면 다음 장 ( 새 열 · 새 양식은 품명 · 규격 · 비고가 켜진 채 )
     - 기본 양식으로 켠 양식이 그 문서의 인쇄에 쓰인다. 기본 양식이 없으면 발주서 = 예전 고정 배치 · 출하지시서 = 처음 배치
     - [초기화] = 고치기 전( 양식을 연 때 · 마지막 저장 )으로 되돌린다 ( 확인창 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newTemplate()">+ 새 양식</BaseButton>
            <span class="text-xs text-gray-400">「기본 양식」으로 켠 양식이 그 문서의 인쇄에 쓰입니다 · 기본 양식이 없으면 원래 배치로 인쇄합니다</span>
        </div>

        <div class="flex flex-1 min-h-0 gap-3">
            <!-- 좌 : 양식 목록 -->
            <div class="w-60 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">양식 <span class="text-xs font-normal text-gray-400">{{ templates.length }}</span></div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!templates.length" class="px-3 py-4 text-sm text-gray-400">{{ loading ? '불러오는 중…' : '만든 양식이 없습니다. [+ 새 양식] 으로 시작하세요.' }}</p>
                    <div v-for="t in templates" :key="t.doc_template_idx" class="px-3 py-2 cursor-pointer text-sm transition-colors"
                        :class="form.doc_template_idx === t.doc_template_idx ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-50 text-gray-700'" @click="pickTemplate(t)">
                        <div class="flex items-center gap-1.5">
                            <span class="truncate font-medium" :class="t.is_use === 'N' ? 'text-gray-400 line-through' : ''">{{ t.template_name }}</span>
                            <span v-if="t.is_default === 'Y'" class="shrink-0 text-[10px] px-1 rounded bg-green-50 text-green-700">기본</span>
                        </div>
                        <div class="text-[11px] text-gray-400">{{ docTypeLabel(t.doc_type) }} · A4 {{ t.orientation === 'L' ? '가로' : '세로' }}</div>
                    </div>
                </div>
            </div>

            <!-- 가운데 + 우 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">{{ form.doc_template_idx ? '양식 수정' : '새 양식' }}</span>
                    <span v-if="dirty" class="text-xs text-amber-600">저장하지 않은 변경이 있습니다</span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="saving || !dirty" title="고치기 전 양식으로 되돌립니다 ( 저장한 내용은 그대로 )" @click="resetForm">초기화</BaseButton>
                    <BaseButton v-if="canWrite && form.doc_template_idx" size="sm" variant="secondary" :disabled="saving" @click="copyTemplate">복사해서 새로</BaseButton>
                    <BaseButton v-if="canDelete && form.doc_template_idx" size="sm" variant="danger" :disabled="saving" @click="removeTemplate">삭제</BaseButton>
                    <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                </div>

                <!-- 양식 정보 -->
                <div class="flex items-end gap-3 flex-wrap px-3 py-2 border-b shrink-0 text-sm">
                    <div class="w-56">
                        <label class="lbl">양식 이름 <span class="text-red-400">*</span></label>
                        <BaseInput v-model="form.template_name" class="w-full" placeholder="예: 발주서 ( 영문 병기 )" maxlength="50" :disabled="!canWrite" />
                    </div>
                    <div class="w-36">
                        <label class="lbl">문서 종류</label>
                        <BaseSelect v-model="form.doc_type" :options="DOC_TYPES" class="w-full" :disabled="!canWrite || !!form.doc_template_idx" @update:model-value="onTypeChange" />
                    </div>
                    <div class="w-28">
                        <label class="lbl">용지 ( A4 )</label>
                        <BaseSelect v-model="form.orientation" :options="ORIENTS" class="w-full" :disabled="!canWrite" />
                    </div>
                    <div class="w-20">
                        <label class="lbl">여백 mm</label>
                        <BaseInput v-model="form.design.margin_mm" type="number" min="3" class="w-full text-right" :disabled="!canWrite" />
                    </div>
                    <BaseCheckbox variant="switch" v-model="form.is_default" true-value="Y" false-value="N" :disabled="!canWrite" class="pb-1.5">기본 양식</BaseCheckbox>
                    <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite" class="pb-1.5">{{ form.is_use === 'Y' ? '사용' : '미사용' }}</BaseCheckbox>
                    <span class="pb-2 text-xs" :class="overflow ? 'text-red-500 font-semibold' : 'text-gray-400'">높이 {{ fmt1(need) }} / {{ pageSize.h }}mm<template v-if="overflow"> — 종이를 넘습니다. 줄 수나 영역 높이를 줄이세요</template></span>
                </div>
                <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0">{{ errorText }}</p>

                <div class="flex flex-1 min-h-0">
                    <!-- 종이 -->
                    <div class="flex-1 min-w-0 overflow-auto bg-gray-200 p-4">
                        <div class="mx-auto w-fit shadow-md" :style="{ zoom }">
                            <DocSheet :doc-type="form.doc_type" :design="form.design" :orientation="form.orientation" :page="samplePage" :images="images"
                                :editable="canWrite" :active="zone" :selected="grid?.selKeys.value ?? null" @pick="onPick" />
                        </div>
                    </div>

                    <!-- 설정 -->
                    <div class="w-[22rem] shrink-0 border-l border-gray-200 flex flex-col text-sm">
                        <div class="inline-flex p-0.5 m-2 rounded bg-gray-100 shrink-0">
                            <button v-for="z in ZONES" :key="z.key" type="button" class="flex-1 px-2 py-1.5 rounded text-xs"
                                :class="zone === z.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'" @click="setZone(z.key)">{{ z.label }}</button>
                        </div>

                        <!-- 품목 표 -->
                        <div v-if="zone === 'table'" class="flex-1 min-h-0 overflow-y-auto px-3 pb-3 space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div><label class="lbl">한 장 줄 수</label><BaseInput v-model="tbl.rows_per_page" type="number" min="1" class="w-full text-right" :disabled="!canWrite" /></div>
                                <div><label class="lbl">줄 높이 mm</label><BaseInput v-model="tbl.row_h_mm" type="number" min="4" class="w-full text-right" :disabled="!canWrite" /></div>
                                <div><label class="lbl">머리글 높이 mm</label><BaseInput v-model="tbl.head_h_mm" type="number" min="4" class="w-full text-right" :disabled="!canWrite" /></div>
                                <div><label class="lbl">글자 크기 pt</label><BaseInput v-model="tbl.size" type="number" min="5" class="w-full text-right" :disabled="!canWrite" /></div>
                            </div>
                            <BaseCheckbox variant="switch" v-model="tbl.sum" true-value="Y" false-value="N" :disabled="!canWrite">합계 줄 ( 수량 · 금액 열 )</BaseCheckbox>

                            <div>
                                <div class="flex items-center mb-1">
                                    <span class="lbl !mb-0">열 <span class="font-normal text-gray-400">{{ tbl.columns.length }}</span></span>
                                    <div class="flex-1"></div>
                                    <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="tbl.columns.length >= 16" @click="addColumn">+ 열</BaseButton>
                                </div>
                                <div v-for="(c, i) in tbl.columns" :key="i" class="mb-1.5 p-1.5 rounded border border-gray-200 space-y-1">
                                    <div class="flex items-center gap-1">
                                        <BaseSelect :model-value="c.field" :options="columnOptions" size="sm" class="flex-1 min-w-0" :disabled="!canWrite" @update:model-value="v => setColumnField(c, v)" />
                                        <button type="button" class="dt-ico" :disabled="!canWrite || i === 0" title="왼쪽으로" @click="moveColumn(i, -1)">←</button>
                                        <button type="button" class="dt-ico" :disabled="!canWrite || i === tbl.columns.length - 1" title="오른쪽으로" @click="moveColumn(i, 1)">→</button>
                                        <button type="button" class="dt-ico hover:!text-red-500" :disabled="!canWrite || tbl.columns.length <= 1" title="열 빼기" @click="tbl.columns.splice(i, 1)">✕</button>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <BaseInput v-model="c.label" size="sm" class="flex-1 min-w-0" placeholder="머리글" maxlength="30" :disabled="!canWrite" />
                                        <BaseInput v-model="c.width" type="number" min="0.2" size="sm" class="w-16 text-right" title="너비 비율" :disabled="!canWrite" />
                                        <BaseSelect v-model="c.align" :options="ALIGNS_OPT" size="sm" class="w-20" :disabled="!canWrite" />
                                        <label class="shrink-0 inline-flex items-center gap-0.5 text-[11px] text-gray-600 cursor-pointer" title="칸 폭을 넘으면 아랫줄로 ( 그 행만 높아지고, 넘치면 다음 장으로 )">
                                            <input type="checkbox" class="accent-blue-600" v-model="c.wrap" :disabled="!canWrite" /> 줄바꿈
                                        </label>
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-400">너비는 비율입니다 ( 열끼리 견줘 나눕니다 ) · 「빈 칸」 열은 손으로 적는 확인란 등에 씁니다</p>
                            </div>
                        </div>

                        <!-- 머리 · 아래 영역 -->
                        <div v-else class="flex-1 min-h-0 overflow-y-auto px-3 pb-3 space-y-3">
                            <div class="grid grid-cols-3 gap-2 items-end">
                                <div><label class="lbl">높이 mm</label><BaseInput v-model="zoneGrid.height_mm" type="number" min="0" class="w-full text-right" :disabled="!canWrite" /></div>
                                <div>
                                    <label class="lbl">열</label>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="dt-step" :disabled="!canWrite || zoneGrid.cols <= 1" @click="grid.setCols(zoneGrid.cols - 1)">−</button>
                                        <span class="w-5 text-center font-semibold">{{ zoneGrid.cols }}</span>
                                        <button type="button" class="dt-step" :disabled="!canWrite || zoneGrid.cols >= MAX_COLS" @click="grid.setCols(zoneGrid.cols + 1)">+</button>
                                    </div>
                                </div>
                                <div>
                                    <label class="lbl">행</label>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="dt-step" :disabled="!canWrite || zoneGrid.rows <= 1" @click="grid.setRows(zoneGrid.rows - 1)">−</button>
                                        <span class="w-5 text-center font-semibold">{{ zoneGrid.rows }}</span>
                                        <button type="button" class="dt-step" :disabled="!canWrite || zoneGrid.rows >= MAX_ROWS" @click="grid.setRows(zoneGrid.rows + 1)">+</button>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="lbl">열 너비 비율</label>
                                <div class="flex flex-wrap gap-1"><input v-for="(w, i) in zoneGrid.col_w" :key="i" v-model.number="zoneGrid.col_w[i]" type="number" min="0.2" max="20" step="0.1" class="dt-ratio" :disabled="!canWrite" :title="`${i + 1}열`" /></div>
                            </div>
                            <div>
                                <label class="lbl">행 높이 비율</label>
                                <div class="flex flex-wrap gap-1"><input v-for="(w, i) in zoneGrid.row_h" :key="i" v-model.number="zoneGrid.row_h[i]" type="number" min="0.2" max="20" step="0.1" class="dt-ratio" :disabled="!canWrite" :title="`${i + 1}행`" /></div>
                            </div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <BaseCheckbox variant="switch" v-model="zoneGrid.border" true-value="Y" false-value="N" :disabled="!canWrite">칸 선</BaseCheckbox>
                                <div class="flex-1"></div>
                                <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !grid.canMerge.value" @click="grid.merge()">합치기</BaseButton>
                                <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !grid.canSplit.value" @click="grid.split()">나누기</BaseButton>
                                <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !grid.sel.value" @click="grid.clear()">비우기</BaseButton>
                            </div>

                            <div class="border border-gray-200 rounded">
                                <div class="px-2.5 py-1.5 border-b bg-gray-50/70 text-xs font-semibold text-gray-600">칸 내용</div>
                                <div class="p-2.5 space-y-2.5">
                                    <p v-if="!grid.sel.value" class="text-gray-400 text-xs">종이에서 칸을 고르세요. Shift + 클릭으로 여러 칸을 골라 합칩니다.</p>
                                    <p v-else-if="!grid.curTile.value" class="text-gray-500 text-xs">{{ grid.selTiles.value.length }}칸을 골랐습니다. [합치기] 로 한 칸으로 만들 수 있습니다.</p>
                                    <template v-else>
                                        <div class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                            <button v-for="k in KINDS" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                :class="(cur?.type ?? null) === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'" @click="setType(k.key)">{{ k.label }}</button>
                                        </div>
                                        <div v-if="cur?.type === 'FIELD'">
                                            <label class="lbl">항목</label>
                                            <BaseSelect v-model="cur.field" :options="fieldOptions" class="w-full" :disabled="!canWrite" />
                                        </div>
                                        <div v-if="cur?.type === 'TEXT'">
                                            <label class="lbl">글자</label>
                                            <BaseInput v-model="cur.text" class="w-full" placeholder="예: 발 주 서" maxlength="200" :disabled="!canWrite" />
                                        </div>
                                        <div v-if="cur?.type === 'IMAGE'">
                                            <label class="lbl">그림 ( 자사정보관리에 올린 것 )</label>
                                            <div class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                                <button v-for="k in IMAGES" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                    :class="cur.image === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'" @click="cur.image = k.key">{{ k.label }}</button>
                                            </div>
                                        </div>
                                        <template v-if="cur?.type === 'FIELD' || cur?.type === 'TEXT'">
                                            <div class="flex items-end gap-3">
                                                <div class="w-20"><label class="lbl">크기 pt</label><BaseInput v-model="cur.size" type="number" min="5" class="w-full text-right" :disabled="!canWrite" /></div>
                                                <BaseCheckbox v-model="cur.bold" :disabled="!canWrite" class="pb-2">굵게</BaseCheckbox>
                                                <BaseCheckbox v-model="cur.wrap" :disabled="!canWrite" class="pb-2" title="칸보다 길면 다음 줄로 넘깁니다 ( 비고 등 )">여러 줄</BaseCheckbox>
                                            </div>
                                        </template>
                                        <div v-if="cur?.type" class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                            <button v-for="k in ALIGNS" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                :class="cur.align === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'" @click="cur.align = k.key">{{ k.label }}</button>
                                        </div>
                                        <BaseCheckbox :model-value="cur?.bg === true" :disabled="!canWrite" @update:model-value="setBg">바탕 회색 ( 항목 이름 칸 )</BaseCheckbox>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import DocSheet from '@core/Components/Doc/DocSheet.vue';
import { DOC_TYPES, FIELDS, COLUMNS, SAMPLE, PAGE, MAX_COLS, MAX_ROWS, docTypeLabel, columnDef, starterDesign, paginate, neededHeight, defaultWrap } from '@core/Components/Doc/docDesign';
import { measureLineHeights } from '@core/Components/Doc/measureLines';
import { useGridEdit } from '@core/Components/Doc/useGridEdit';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';

defineOptions({ layout: AppLayout });

const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);
const { showFlash } = useToast();

const ZONES      = [{ key: 'head', label: '머리 영역' }, { key: 'table', label: '품목 표' }, { key: 'foot', label: '아래 영역' }];
const ORIENTS    = [{ value: 'P', label: '세로' }, { value: 'L', label: '가로' }];
const KINDS      = [{ key: null, label: '비움' }, { key: 'FIELD', label: '항목' }, { key: 'TEXT', label: '글자' }, { key: 'IMAGE', label: '그림' }];
const IMAGES     = [{ key: 'logo', label: '로고' }, { key: 'seal', label: '직인' }];
const ALIGNS     = [{ key: 'left', label: '왼쪽' }, { key: 'center', label: '가운데' }, { key: 'right', label: '오른쪽' }];
const ALIGNS_OPT = ALIGNS.map(a => ({ value: a.key, label: a.label }));
const fmt1       = (v) => (Math.round(v * 10) / 10).toString();

const templates = ref([]);
const images    = ref({});
const loading   = ref(true);
const saving    = ref(false);
const errorText = ref('');
const zone      = ref('head');

const clone = (v) => JSON.parse(JSON.stringify(v));
const blank = (type = 'PURCHASE_ORDER') => ({
    doc_template_idx: null, template_name: '', doc_type: type, orientation: 'P', is_default: 'N', is_use: 'Y', remark: null, design: starterDesign(type),
});
const form = reactive(blank());
const snapshot = ref('');   // 마지막으로 열거나 저장한 내용 ( 반응형 — 저장 뒤 '저장하지 않은 변경' 표시가 바로 꺼지게 )
const payload = () => ({
    doc_template_idx: form.doc_template_idx, template_name: form.template_name, doc_type: form.doc_type, orientation: form.orientation,
    is_default: form.is_default, is_use: form.is_use, remark: form.remark, design: clone(form.design),
});
const dirty = computed(() => JSON.stringify(payload()) !== snapshot.value);

// unsaved = 아직 저장한 적 없는 내용으로 연다 ( 복사해서 새로 )
let origin = null, originUnsaved = false;
function load(data, unsaved = false) {
    origin = clone(data); originUnsaved = unsaved;   // [초기화] 가 돌아갈 곳
    Object.assign(form, clone(data));
    grids.head.clearPick(); grids.foot.clearPick();
    errorText.value = '';
    snapshot.value = unsaved ? '' : JSON.stringify(payload());
}
async function resetForm() {
    if (!origin || !dirty.value) return;
    const r = await showAlert('초기화', '고친 내용을 버리고 수정하기 전 양식으로 되돌립니다.\n되돌릴까요?', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    load(origin, originUnsaved);
}

// ---- 종이 ( 예시 문서 ) ----
const pageSize   = computed(() => PAGE[form.orientation] ?? PAGE.P);
const zoom       = computed(() => form.orientation === 'L' ? 0.62 : 0.8);
// 예시 문서 첫 장 — 줄바꿈 열이 있으면 줄 높이를 재서 ( 11-24 · 인쇄와 같은 셈 )
const sampleHeights = ref(null);
watch(() => [form.doc_type, form.design, form.orientation], () => nextTick(() => {
    sampleHeights.value = form.design?.table ? measureLineHeights(form.doc_type, form.design, SAMPLE[form.doc_type]?.lines ?? [], form.orientation) : null;
}), { deep: true, immediate: true });
const samplePage = computed(() => paginate(form.doc_type, form.design, SAMPLE[form.doc_type], { writer: page.props.auth?.user?.user_name ?? '', today: '2026-11-18' }, sampleHeights.value)[0]);
const need       = computed(() => neededHeight({ ...form.design, margin_mm: Number(form.design.margin_mm) || 0 }));
const overflow   = computed(() => need.value > pageSize.value.h + 0.01);

// ---- 영역 ----
const newCell = (r, c) => ({ r, c, rs: 1, cs: 1, type: null, field: null, text: null, image: null, size: 9, bold: false, align: 'left', wrap: false, bg: false });
const grids = {
    head: useGridEdit(() => form.design.head, { maxCols: MAX_COLS, maxRows: MAX_ROWS, blank: newCell }),
    foot: useGridEdit(() => form.design.foot, { maxCols: MAX_COLS, maxRows: MAX_ROWS, blank: newCell }),
};
const grid     = computed(() => grids[zone.value] ?? null);   // 품목 표면 null
const zoneGrid = computed(() => form.design[zone.value]);
const tbl      = computed(() => form.design.table);
const cur      = computed(() => grid.value?.cur.value ?? null);
function setZone(z) {
    if (zone.value === z) return;
    grids.head.clearPick(); grids.foot.clearPick();
    zone.value = z;
}
function onPick(z, t, ev) {
    if (zone.value !== z) setZone(z);
    if (z !== 'table') grids[z].pick(t, ev);
}

const fieldOptions = computed(() => FIELDS[form.doc_type].map(f => ({ value: f.key, label: f.label })));
function setType(type) {
    const g = grid.value;
    if (!g?.curTile.value || (type === null && !cur.value)) return;
    const cell = g.ensure();
    cell.type = type;
    if (type === 'FIELD') cell.field ??= FIELDS[form.doc_type][0].key;
    if (type === 'TEXT')  cell.text  ??= '';
    if (type === 'IMAGE') { cell.image ??= 'logo'; cell.align = 'center'; }
    g.prune();
}
function setBg(v) {
    const g = grid.value;
    if (!g?.curTile.value) return;
    g.ensure().bg = v === true;
    g.prune();
}

// ---- 품목 표의 열 ----
const columnOptions = computed(() => COLUMNS[form.doc_type].map(c => ({ value: c.key, label: c.label })));
function setColumnField(c, key) {
    const before = columnDef(form.doc_type, c.field), d = columnDef(form.doc_type, key);
    if (!c.label || c.label === before?.head) c.label = key === 'blank' ? '' : d.head;   // 머리글을 고친 적이 없으면 같이 바꾼다
    c.field = key;
    c.align = d.align;
    c.wrap  = defaultWrap(key);   // 품명 · 규격 · 비고 = 줄바꿈 ( 11-24 )
}
function addColumn() {
    const used = new Set(tbl.value.columns.map(c => c.field));
    const d = COLUMNS[form.doc_type].find(c => !used.has(c.key)) ?? columnDef(form.doc_type, 'blank');
    tbl.value.columns.push({ field: d.key, label: d.key === 'blank' ? '' : d.head, width: 1, align: d.align, wrap: defaultWrap(d.key) });
}
function moveColumn(i, step) {
    const list = tbl.value.columns;
    list.splice(i + step, 0, ...list.splice(i, 1));
}

// ---- 양식 고르기 · 새로 · 복사 ----
async function leaveOk() {
    if (!dirty.value || !canWrite.value) return true;
    const r = await showAlert('저장하지 않은 변경', '고치던 내용을 버리고 넘어갈까요?', 'warning', 'confirm');
    return r.isConfirmed;
}
async function pickTemplate(t) {
    if (t.doc_template_idx === form.doc_template_idx || !(await leaveOk())) return;
    load(t);
}
async function newTemplate() {
    if (!(await leaveOk())) return;
    load(blank());
}
async function copyTemplate() {
    if (!(await leaveOk())) return;
    const src = templates.value.find(t => t.doc_template_idx === form.doc_template_idx);
    load({ ...clone(src), doc_template_idx: null, template_name: `${src.template_name} 복사`.slice(0, 50), is_default: 'N', is_use: 'Y' }, true);
}
function onTypeChange(v) {
    form.doc_type = v;
    form.design   = starterDesign(v);   // 문서마다 항목 · 열이 다르다 — 새 양식만 바꿀 수 있다
    grids.head.clearPick(); grids.foot.clearPick();
}

async function loadTemplates() {
    try {
        const { data } = await window.axios.get(route('config.docTemplate.list'));
        templates.value = data.templates ?? [];
        images.value    = data.images ?? {};
    } catch (e) {
        showFlash('문서 양식을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}

async function save() {
    errorText.value = '';
    if (!String(form.template_name ?? '').trim()) { errorText.value = '양식 이름을 넣으세요.'; return; }
    if (overflow.value) { errorText.value = `배치가 종이 높이( ${pageSize.value.h}mm )를 넘습니다. 한 장 줄 수나 영역 높이를 줄이세요.`; return; }
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('config.docTemplate.save'), payload());
        await loadTemplates();
        const saved = templates.value.find(t => t.doc_template_idx === data.template.doc_template_idx);
        if (saved) load(saved);
        showFlash('저장되었습니다.');
    } catch (e) {
        const s = e.response?.status;
        if (s === 422)      { errorText.value = Object.values(e.response.data.errors ?? {}).flat().join(' / '); showFlash('입력값을 확인하세요', true); }
        else if (s === 403) showFlash('저장 권한이 없습니다.', true);
        else                showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

async function removeTemplate() {
    const was = form.is_default === 'Y' ? `\n기본 양식입니다 — 지우면 ${docTypeLabel(form.doc_type)}는 원래 배치로 인쇄됩니다.` : '';
    const r = await showAlert('양식 삭제', `[ ${form.template_name} ] 양식을 지웁니다.${was}`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        await window.axios.post(route('config.docTemplate.del'), { doc_template_idx: form.doc_template_idx });
        await loadTemplates();
        load(templates.value[0] ?? blank());
        showFlash('삭제되었습니다.');
    } catch (e) {
        showFlash(e.response?.status === 403 ? '삭제 권한이 없습니다.' : '삭제에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await loadTemplates();
    load(templates.value[0] ?? blank());
});
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-size: 0.75rem; font-weight: 500; color: #6b7280; }
.dt-step { width: 1.5rem; height: 1.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; background: #fff; line-height: 1; color: #4b5563; }
.dt-step:hover:not(:disabled) { background: #f3f4f6; }
.dt-step:disabled, .dt-ico:disabled { opacity: 0.35; }
.dt-ico { flex: none; width: 1.5rem; height: 1.75rem; border-radius: 0.25rem; color: #6b7280; }
.dt-ico:hover:not(:disabled) { background: #f3f4f6; color: #111827; }
.dt-ratio { width: 3rem; height: 1.75rem; padding: 0 0.25rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.75rem; text-align: center; }
.dt-ratio:focus { outline: none; border-color: #2563eb; }
</style>

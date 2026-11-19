<!-- 라벨 양식 ( 기준정보관리 > 라벨 양식 · 11-18 ) — 라벨의 크기 · 칸 배치를 만든다 ( 바코드 · QR )
     - 좌 : 양식 목록 ( 대상별 · 기본 양식 표시 ) / 우 : 양식 정보 + 격자 편집
     - 격자 편집 : 행 × 열로 나눈 칸을 눌러 고르고( Shift + 클릭 = 여러 칸 ) [합치기] · [나누기] — 칸마다 내용( 항목 · 글자 · 코드 )과 글자 모양
       [초기화] = 고치기 전( 양식을 연 때 · 마지막 저장 )으로 되돌린다 ( 확인창 )
       열 너비 · 행 높이는 라벨 위 · 왼쪽의 비율 칸으로 · 라벨은 예시 데이터로 그대로 그린다 ( LabelGridSheet — 인쇄와 같은 부품 )
     - 저장된 양식은 보기 화면( 라벨 미리 보기 + [격자 편집] / [격자로 바꾸기] )으로 열고, 단추를 눌러야 격자 편집이 열린다 · 저장 · 초기화하면 보기 화면으로 돌아온다
     - 처음 들어 있는 양식( 격자 없음 )은 고정 배치 — [격자로 바꾸기] 를 누르면 격자로 고칠 수 있다 ( 저장하기 전까지는 그대로 )
     - 코드 칸의 기본 종류 = 정책 barcode.code_type · 발행한 라벨이 있는 양식은 못 지운다( 미사용으로 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newTemplate()">+ 새 양식</BaseButton>
            <span class="text-xs text-gray-400">라벨 발행 창에서 여기서 만든 양식을 고릅니다 · 대상마다 기본 양식 하나</span>
        </div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 양식 목록 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">양식 <span class="text-xs font-normal text-gray-400">{{ templates.length }}</span></div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!templates.length" class="px-3 py-4 text-sm text-gray-400">{{ loading ? '불러오는 중…' : '등록된 양식이 없습니다.' }}</p>
                    <div v-for="t in templates" :key="t.label_template_idx" class="px-3 py-2 cursor-pointer text-sm transition-colors"
                        :class="form.label_template_idx === t.label_template_idx ? 'bg-blue-50 text-blue-700' : 'hover:bg-gray-50 text-gray-700'"
                        @click="pickTemplate(t)">
                        <div class="flex items-center gap-1.5">
                            <span class="truncate font-medium" :class="t.is_use === 'N' ? 'text-gray-400 line-through' : ''">{{ t.template_name }}</span>
                            <span v-if="t.is_default === 'Y'" class="shrink-0 text-[10px] px-1 rounded bg-green-50 text-green-700">기본</span>
                        </div>
                        <div class="text-[11px] text-gray-400">{{ targetLabel(t.target) }} · {{ t.width_mm }} × {{ t.height_mm }}mm · {{ t.design ? '격자' : '고정 배치' }}<template v-if="t.used_count"> · 발행 {{ t.used_count }}건</template></div>
                    </div>
                </div>
            </div>

            <!-- 우 : 편집 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">{{ form.label_template_idx ? '양식 수정' : '새 양식' }}</span>
                    <span v-if="dirty" class="text-xs text-amber-600">저장하지 않은 변경이 있습니다</span>
                    <span v-else-if="converted" class="text-xs text-gray-400">격자 배치로 열었습니다 — [저장] 을 눌러야 이 양식에 적용됩니다</span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="saving || (!dirty && !converted)" title="고치기 전 양식으로 되돌립니다 ( 저장한 내용은 그대로 )" @click="resetForm">초기화</BaseButton>
                    <BaseButton v-if="canWrite && form.label_template_idx" size="sm" variant="secondary" :disabled="saving" @click="copyTemplate">복사해서 새로</BaseButton>
                    <BaseButton v-if="canDelete && form.label_template_idx" size="sm" variant="danger" :disabled="saving" @click="removeTemplate">삭제</BaseButton>
                    <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                </div>

                <div class="flex-1 min-h-0 overflow-auto p-4 space-y-4 text-sm">
                    <!-- 양식 정보 -->
                    <div class="flex items-end gap-3 flex-wrap">
                        <div class="w-64">
                            <label class="lbl">양식 이름 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.template_name" class="w-full" placeholder="예: 자재식별표 QR 80×50" maxlength="50" :disabled="!canWrite" />
                        </div>
                        <div class="w-72">
                            <label class="lbl">대상</label>
                            <BaseSelect v-model="form.target" :options="TARGETS" class="w-full" :disabled="!canWrite || !!form.label_template_idx" @update:model-value="onTargetChange" />
                        </div>
                        <div class="w-24">
                            <label class="lbl">가로 mm</label>
                            <BaseInput v-model="form.width_mm" type="number" min="20" class="w-full text-right" :disabled="!canWrite" />
                        </div>
                        <div class="w-24">
                            <label class="lbl">세로 mm</label>
                            <BaseInput v-model="form.height_mm" type="number" min="15" class="w-full text-right" :disabled="!canWrite" />
                        </div>
                        <BaseCheckbox variant="switch" v-model="form.is_default" true-value="Y" false-value="N" :disabled="!canWrite" class="pb-1.5">기본 양식</BaseCheckbox>
                        <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite" class="pb-1.5">{{ form.is_use === 'Y' ? '사용' : '미사용' }}</BaseCheckbox>
                    </div>
                    <p v-if="errorText" class="text-xs text-red-500">{{ errorText }}</p>

                    <!-- 보기 화면 : 격자 양식 — [격자 편집] 을 눌러야 고친다 ( 저장하면 여기로 돌아온다 ) -->
                    <div v-if="form.design && !editing" class="flex gap-4 items-start">
                        <div class="lt-stage"><div :style="{ zoom }"><LabelGridSheet :label="sampleLabel" :design="form.design" class="shadow" /></div></div>
                        <div class="text-sm text-gray-600 space-y-2 max-w-sm">
                            <p><b>격자 배치</b> 양식입니다. 라벨의 값은 예시이고, 인쇄는 {{ form.width_mm }} × {{ form.height_mm }}mm 로 나옵니다.</p>
                            <p class="text-gray-500">칸 배치를 고치려면 격자 편집을 여세요. 이름 · 크기 · 기본 양식은 위에서 바로 고칠 수 있습니다.</p>
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="editing = true">격자 편집</BaseButton>
                        </div>
                    </div>

                    <!-- 보기 화면 : 고정 배치 양식 -->
                    <div v-else-if="!form.design" class="flex gap-4 items-start">
                        <div class="lt-stage"><LabelSheet :label="legacyLabel" class="shadow" /></div>
                        <div class="text-sm text-gray-600 space-y-2 max-w-sm">
                            <p>처음부터 들어 있는 <b>고정 배치</b> 양식입니다. 칸 배치는 정해져 있고, 아래 코드는 시스템 설정 「라벨 코드 종류」( 지금 {{ codeType === 'QR' ? 'QR 코드' : '바코드' }} )를 따릅니다.</p>
                            <p class="text-gray-500">배치를 직접 고치려면 격자로 바꾸세요. 저장하기 전까지는 원래 양식 그대로입니다.</p>
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="toGrid">격자로 바꾸기</BaseButton>
                        </div>
                    </div>

                    <!-- 격자 편집 -->
                    <template v-else>
                        <div class="flex items-center gap-3 flex-wrap px-3 py-2 rounded border border-gray-200 bg-gray-50/60">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-500 mr-1">열</span>
                                <button type="button" class="lt-step" :disabled="!canWrite || form.design.cols <= 1" @click="setCols(form.design.cols - 1)">−</button>
                                <span class="w-6 text-center font-semibold">{{ form.design.cols }}</span>
                                <button type="button" class="lt-step" :disabled="!canWrite || form.design.cols >= MAX_COLS" @click="setCols(form.design.cols + 1)">+</button>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-500 mr-1">행</span>
                                <button type="button" class="lt-step" :disabled="!canWrite || form.design.rows <= 1" @click="setRows(form.design.rows - 1)">−</button>
                                <span class="w-6 text-center font-semibold">{{ form.design.rows }}</span>
                                <button type="button" class="lt-step" :disabled="!canWrite || form.design.rows >= MAX_ROWS" @click="setRows(form.design.rows + 1)">+</button>
                            </div>
                            <BaseCheckbox variant="switch" v-model="form.design.border" true-value="Y" false-value="N" :disabled="!canWrite">칸 선</BaseCheckbox>
                            <span class="w-px h-5 bg-gray-300"></span>
                            <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !canMerge" @click="merge">합치기</BaseButton>
                            <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !canSplit" @click="split">나누기</BaseButton>
                            <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !sel" @click="clearSel">칸 비우기</BaseButton>
                            <span class="text-xs text-gray-400">칸을 눌러 고르고, Shift + 클릭으로 여러 칸을 골라 합칩니다</span>
                        </div>

                        <div class="flex gap-4 items-start">
                            <!-- 라벨 ( 예시 데이터 ) + 열 너비 · 행 높이 비율 -->
                            <div class="lt-stage">
                                <div :style="{ zoom }">
                                    <div class="flex">
                                        <div class="lt-corner" title="열 너비 · 행 높이는 비율입니다 ( 1 = 기본 )">비율</div>
                                        <div class="lt-cols" :style="{ width: `${form.width_mm}mm`, gridTemplateColumns: frs(form.design.col_w) }">
                                            <input v-for="(w, i) in form.design.col_w" :key="i" v-model.number="form.design.col_w[i]" type="number" min="0.2" max="20" step="0.1" class="lt-ratio" :disabled="!canWrite" :title="`${i + 1}열 너비 비율`" />
                                        </div>
                                    </div>
                                    <div class="flex">
                                        <div class="lt-rows" :style="{ height: `${form.height_mm}mm`, gridTemplateRows: frs(form.design.row_h) }">
                                            <input v-for="(hh, i) in form.design.row_h" :key="i" v-model.number="form.design.row_h[i]" type="number" min="0.2" max="20" step="0.1" class="lt-ratio" :disabled="!canWrite" :title="`${i + 1}행 높이 비율`" />
                                        </div>
                                        <LabelGridSheet :label="sampleLabel" :design="form.design" :editable="canWrite" :selected="selKeys" class="shadow" @pick="pick" />
                                    </div>
                                </div>
                            </div>

                            <!-- 고른 칸 -->
                            <div class="w-72 shrink-0 border border-gray-200 rounded">
                                <div class="px-3 py-2 border-b bg-gray-50/70 text-sm font-semibold text-gray-600">칸 내용</div>
                                <div class="p-3 space-y-3">
                                    <p v-if="!sel" class="text-gray-400 text-sm">왼쪽 라벨에서 칸을 고르세요.</p>
                                    <p v-else-if="!curTile" class="text-gray-500 text-sm">{{ selCount }}칸을 골랐습니다. [합치기] 로 한 칸으로 만들 수 있습니다.</p>
                                    <template v-else>
                                        <p class="text-xs text-gray-400">{{ curTile.r + 1 }}행 {{ curTile.c + 1 }}열<template v-if="curTile.rs > 1 || curTile.cs > 1"> · {{ curTile.rs }} × {{ curTile.cs }} 칸을 합침</template></p>
                                        <div class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                            <button v-for="k in KINDS" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                :class="(cur?.type ?? null) === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                                @click="setType(k.key)">{{ k.label }}</button>
                                        </div>

                                        <div v-if="cur?.type === 'FIELD'">
                                            <label class="lbl">항목</label>
                                            <BaseSelect v-model="cur.field" :options="fieldOptions" class="w-full" :disabled="!canWrite" />
                                        </div>
                                        <div v-if="cur?.type === 'TEXT'">
                                            <label class="lbl">글자</label>
                                            <BaseInput v-model="cur.text" class="w-full" placeholder="예: 품명" maxlength="100" :disabled="!canWrite" />
                                        </div>
                                        <template v-if="cur?.type === 'CODE'">
                                            <div>
                                                <label class="lbl">코드 종류</label>
                                                <div class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                                    <button v-for="k in CODES" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                        :class="cur.code === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                                        @click="cur.code = k.key">{{ k.label }}</button>
                                                </div>
                                                <p class="mt-1 text-[11px] text-gray-400">{{ cur.code === 'QR' ? 'QR 은 정사각형입니다. 칸을 네모에 가깝게 잡으세요.' : '바코드는 가로로 긴 칸에 두세요. 좁으면 스캐너가 못 읽습니다.' }}</p>
                                            </div>
                                            <div>
                                                <label class="lbl">코드 크기 % <span class="font-normal text-gray-400">( 30 ~ 100 · 100 = 칸을 다 채움 )</span></label>
                                                <div class="flex items-center gap-2">
                                                    <input type="range" min="30" max="100" step="5" class="flex-1" :value="cur.scale ?? 100" :disabled="!canWrite" @input="cur.scale = Number($event.target.value)" />
                                                    <span class="w-10 text-right text-sm tabular-nums">{{ cur.scale ?? 100 }}%</span>
                                                </div>
                                            </div>
                                            <BaseCheckbox v-model="cur.show_no" :disabled="!canWrite">코드 아래에 번호 글자 표시</BaseCheckbox>
                                        </template>

                                        <template v-if="cur?.type && (cur.type !== 'CODE' || cur.show_no)">
                                            <div class="flex items-end gap-3">
                                                <div class="w-24">
                                                    <label class="lbl">글자 크기 pt</label>
                                                    <BaseInput v-model="cur.size" type="number" min="4" class="w-full text-right" :disabled="!canWrite" />
                                                </div>
                                                <BaseCheckbox v-model="cur.bold" :disabled="!canWrite" class="pb-2">굵게</BaseCheckbox>
                                            </div>
                                            <div v-if="cur.type !== 'CODE'">
                                                <label class="lbl">정렬</label>
                                                <div class="inline-flex p-0.5 rounded bg-gray-100 w-full">
                                                    <button v-for="k in ALIGNS" :key="k.key" type="button" class="flex-1 px-1 py-1 rounded text-xs" :disabled="!canWrite"
                                                        :class="cur.align === k.key ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                                        @click="cur.align = k.key">{{ k.label }}</button>
                                                </div>
                                            </div>
                                        </template>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400">라벨의 값은 예시입니다. 글자가 칸보다 길면 잘려서( … ) 나옵니다 · 화면은 {{ Math.round(zoom * 100) }}% 로 키워 보여 줍니다 ( 인쇄는 {{ form.width_mm }} × {{ form.height_mm }}mm )</p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import LabelSheet from '@core/Components/Label/LabelSheet.vue';
import LabelGridSheet from '@core/Components/Label/LabelGridSheet.vue';
import { TARGETS, FIELDS, SAMPLE, MAX_COLS, MAX_ROWS, targetLabel, tilesOf, starterDesign } from '@core/Components/Label/labelDesign';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';

defineOptions({ layout: AppLayout });

const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);
const { showFlash } = useToast();

const KINDS  = [{ key: null, label: '비움' }, { key: 'FIELD', label: '항목' }, { key: 'TEXT', label: '글자' }, { key: 'CODE', label: '코드' }];
const CODES  = [{ key: 'BARCODE', label: '바코드' }, { key: 'QR', label: 'QR' }];
const ALIGNS = [{ key: 'left', label: '왼쪽' }, { key: 'center', label: '가운데' }, { key: 'right', label: '오른쪽' }];

const templates = ref([]);
const codeType  = ref('BARCODE');
const loading   = ref(true);
const saving    = ref(false);
const errorText = ref('');

const clone = (v) => JSON.parse(JSON.stringify(v));
const blank = () => ({
    label_template_idx: null, template_name: '', target: 'LOT', width_mm: 100, height_mm: 72,
    is_default: 'N', is_use: 'Y', remark: null, layout: 'GRID', design: starterDesign('LOT', codeType.value),
});
const form = reactive(blank());
const editing  = ref(false);   // 격자 편집 화면 ( false = 보기 화면 — [격자 편집] · [격자로 바꾸기] 로 연다 )
const snapshot = ref('');   // 마지막으로 열거나 저장한 내용 ( 반응형 — 저장 뒤 '저장하지 않은 변경' 표시가 바로 꺼지게 )
const payload  = () => ({
    label_template_idx: form.label_template_idx, template_name: form.template_name, target: form.target,
    width_mm: Number(form.width_mm), height_mm: Number(form.height_mm), is_default: form.is_default, is_use: form.is_use, remark: form.remark,
    design: form.design ? clone(form.design) : null,
});
const dirty = computed(() => JSON.stringify(payload()) !== snapshot.value);
// unsaved = 아직 저장한 적 없는 내용으로 연다 ( 복사해서 새로 — 열자마자 저장할 것이 있다 )
let origin = null, originUnsaved = false;
function load(data, unsaved = false) {
    origin = clone(data); originUnsaved = unsaved;   // [초기화] 가 돌아갈 곳
    Object.assign(form, clone(data));
    converted.value = false;
    editing.value   = !data.label_template_idx;   // 저장된 양식은 보기 화면으로 연다 ( 저장 · 초기화 뒤에도 ) · 새 양식은 바로 편집
    sel.value = null; anchor = null; errorText.value = '';
    snapshot.value = unsaved ? '' : JSON.stringify(payload());
}
// [초기화] : 고치기 전( 이 양식을 연 때 · 마지막으로 저장한 때 )으로 되돌린다
async function resetForm() {
    if (!origin || (!dirty.value && !converted.value)) return;
    const r = await showAlert('초기화', '고친 내용을 버리고 수정하기 전 양식으로 되돌립니다.\n되돌릴까요?', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    load(origin, originUnsaved);
}

// ---- 라벨 그리기 ( 예시 데이터 ) ----
const sampleLabel = computed(() => ({ ...SAMPLE[form.target], width_mm: Number(form.width_mm) || 100, height_mm: Number(form.height_mm) || 72 }));
const legacyLabel = computed(() => ({ ...sampleLabel.value, layout: form.layout, design: null, code_type: codeType.value }));
const zoom = computed(() => Math.max(0.9, Math.min(2.2, +(560 / ((Number(form.width_mm) || 100) * 3.78)).toFixed(2))));
const frs  = (list) => list.map(v => `minmax(0, ${Number(v) > 0 ? Number(v) : 1}fr)`).join(' ');
const fieldOptions = computed(() => FIELDS[form.target].map(f => ({ value: f.key, label: f.label })));

// ---- 칸 고르기 : sel = 고른 네모 { r0, c0, r1, c1 } ( 합친 칸은 통째로 ) ----
const sel  = ref(null);
let anchor = null;
const tiles = computed(() => form.design ? tilesOf(form.design) : []);
const inSel = (t, s) => t.r >= s.r0 && t.c >= s.c0 && t.r + t.rs - 1 <= s.r1 && t.c + t.cs - 1 <= s.c1;
const hits  = (t, s) => t.r <= s.r1 && t.r + t.rs - 1 >= s.r0 && t.c <= s.c1 && t.c + t.cs - 1 >= s.c0;
const rect  = (t) => ({ r0: t.r, c0: t.c, r1: t.r + t.rs - 1, c1: t.c + t.cs - 1 });
function pick(t, ev) {
    if (!ev.shiftKey || !anchor) {
        anchor = rect(t);
        sel.value = rect(t);
        return;
    }
    // 처음 칸 ~ 누른 칸의 네모 · 걸친 합친 칸은 통째로 넣는다
    const b = rect(t);
    let s = { r0: Math.min(anchor.r0, b.r0), c0: Math.min(anchor.c0, b.c0), r1: Math.max(anchor.r1, b.r1), c1: Math.max(anchor.c1, b.c1) };
    for (let grown = true; grown;) {
        grown = false;
        for (const x of tiles.value) {
            if (hits(x, s) && !inSel(x, s)) {
                const k = rect(x);
                s = { r0: Math.min(s.r0, k.r0), c0: Math.min(s.c0, k.c0), r1: Math.max(s.r1, k.r1), c1: Math.max(s.c1, k.c1) };
                grown = true;
            }
        }
    }
    sel.value = s;
}
const selTiles = computed(() => sel.value ? tiles.value.filter(t => inSel(t, sel.value)) : []);
const selKeys  = computed(() => new Set(selTiles.value.map(t => `${t.r}:${t.c}`)));
const selCount = computed(() => selTiles.value.length);
const curTile  = computed(() => selTiles.value.length === 1 ? selTiles.value[0] : null);
const cur      = computed(() => curTile.value?.cell ?? null);
const canMerge = computed(() => selTiles.value.length > 1);
const canSplit = computed(() => !!curTile.value && (curTile.value.rs > 1 || curTile.value.cs > 1));

const newCell = (r, c, rs = 1, cs = 1) => ({ r, c, rs, cs, type: null, field: null, text: null, code: null, show_no: null, size: 9, bold: false, align: 'left' });
function setType(type) {
    const t = curTile.value;
    if (!t) return;
    let cell = t.cell;
    if (!cell) {
        if (type === null) return;
        cell = newCell(t.r, t.c);
        form.design.cells.push(cell);
        cell = form.design.cells[form.design.cells.length - 1];
    }
    cell.type = type;
    if (type === 'FIELD') cell.field ??= FIELDS[form.target][1].key;
    if (type === 'TEXT')  cell.text  ??= '';
    if (type === 'CODE') { cell.code ??= codeType.value; cell.show_no ??= cell.code !== 'QR'; cell.scale ??= cell.code === 'QR' ? 100 : 80; cell.align = 'center'; }
    if (type === null && cell.rs === 1 && cell.cs === 1) form.design.cells = form.design.cells.filter(x => x !== cell);
}
function merge() {
    const s = sel.value;
    if (!canMerge.value) return;
    const inside = selTiles.value.map(t => t.cell).filter(Boolean);
    const keep   = inside.find(c => c.type) ?? newCell(s.r0, s.c0);   // 내용이 있는 첫 칸을 남긴다
    form.design.cells = form.design.cells.filter(c => !inside.includes(c));
    form.design.cells.push({ ...keep, r: s.r0, c: s.c0, rs: s.r1 - s.r0 + 1, cs: s.c1 - s.c0 + 1 });
    anchor = { ...s };
}
function split() {
    const cell = cur.value;
    if (!cell) return;
    cell.rs = 1; cell.cs = 1;
    if (!cell.type) form.design.cells = form.design.cells.filter(x => x !== cell);
    sel.value = { r0: curTile.value?.r ?? cell.r, c0: curTile.value?.c ?? cell.c, r1: cell.r, c1: cell.c };
    anchor = { ...sel.value };
}
function clearSel() {
    const inside = selTiles.value.map(t => t.cell).filter(Boolean);
    inside.forEach(c => { c.type = null; });
    form.design.cells = form.design.cells.filter(c => c.type || c.rs > 1 || c.cs > 1);
}
// 열 · 행 수 : 줄이면 밖으로 나간 칸은 지우고 걸친 칸은 줄인다
function setCols(n) {
    const d = form.design;
    n = Math.max(1, Math.min(MAX_COLS, n));
    d.cells = d.cells.filter(c => c.c < n);
    d.cells.forEach(c => { c.cs = Math.min(c.cs, n - c.c); });
    d.col_w = Array.from({ length: n }, (_, i) => d.col_w[i] ?? 1);
    d.cols  = n;
    sel.value = null; anchor = null;
}
function setRows(n) {
    const d = form.design;
    n = Math.max(1, Math.min(MAX_ROWS, n));
    d.cells = d.cells.filter(c => c.r < n);
    d.cells.forEach(c => { c.rs = Math.min(c.rs, n - c.r); });
    d.row_h = Array.from({ length: n }, (_, i) => d.row_h[i] ?? 1);
    d.rows  = n;
    sel.value = null; anchor = null;
}

// ---- 양식 고르기 · 새로 · 복사 ----
async function leaveOk() {
    if (!dirty.value || !canWrite.value) return true;
    const r = await showAlert('저장하지 않은 변경', '고치던 내용을 버리고 넘어갈까요?', 'warning', 'confirm');
    return r.isConfirmed;
}
async function pickTemplate(t) {
    if (t.label_template_idx === form.label_template_idx || !(await leaveOk())) return;
    load(t);
}
async function newTemplate() {
    if (!(await leaveOk())) return;
    load(blank());
}
async function copyTemplate() {
    if (!(await leaveOk())) return;
    const src = templates.value.find(t => t.label_template_idx === form.label_template_idx);
    load({ ...clone(src), label_template_idx: null, template_name: `${src.template_name} 복사`.slice(0, 50), is_default: 'N', is_use: 'Y',
        design: src.design ? clone(src.design) : starterDesign(src.target, codeType.value), layout: 'GRID' }, true);
}
function onTargetChange(v) {
    form.target = v;
    form.design = starterDesign(v, codeType.value);   // 대상마다 항목이 다르다 — 새 양식만 바꿀 수 있다
    sel.value = null; anchor = null;
}
// [격자로 바꾸기] : 격자 편집을 여는 것 — 그 뒤에 무엇을 고쳐야 '저장하지 않은 변경' 으로 본다 ( 누르자마자 뜨지 않게 )
//   저장하기 전에는 원래 고정 배치 그대로 · [초기화] 는 고정 배치로 되돌린다
const converted = ref(false);
function toGrid() {
    form.design = starterDesign(form.target, codeType.value);
    editing.value = true;
    snapshot.value  = JSON.stringify(payload());
    converted.value = true;
}

async function loadTemplates() {
    try {
        const { data } = await window.axios.get(route('label.template.list'));
        templates.value = data.templates ?? [];
        codeType.value  = data.code_type === 'QR' ? 'QR' : 'BARCODE';
    } catch (e) {
        showFlash('라벨 양식을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}

async function save() {
    errorText.value = '';
    if (!String(form.template_name ?? '').trim()) { errorText.value = '양식 이름을 넣으세요.'; return; }
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('label.template.save'), payload());
        await loadTemplates();
        const saved = templates.value.find(t => t.label_template_idx === data.template.label_template_idx);
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
    const r = await showAlert('양식 삭제', `[ ${form.template_name} ] 양식을 지웁니다.\n발행한 라벨이 있는 양식은 지울 수 없습니다 ( 미사용으로 바꾸세요 ).`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        await window.axios.post(route('label.template.del'), { label_template_idx: form.label_template_idx });
        await loadTemplates();
        load(templates.value[0] ?? blank());
        showFlash('삭제되었습니다.');
    } catch (e) {
        const s = e.response?.status;
        showFlash(s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : s === 403 ? '삭제 권한이 없습니다.' : '삭제에 실패했습니다.', true);
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
.lt-stage { flex: none; padding: 1rem; background: #e5e7eb; border-radius: 0.25rem; overflow: auto; max-width: 100%; }
.lt-step { width: 1.5rem; height: 1.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; background: #fff; line-height: 1; color: #4b5563; }
.lt-step:hover:not(:disabled) { background: #f3f4f6; }
.lt-step:disabled { opacity: 0.4; }
/* 비율 칸 — 라벨과 같은 격자( mm )로 줄을 맞춘다. 라벨 안쪽 여백 1.5mm */
.lt-corner { width: 9mm; height: 5mm; font-size: 5.5pt; color: #6b7280; display: flex; align-items: center; justify-content: center; }
.lt-cols { display: grid; height: 5mm; padding: 0 1.5mm; box-sizing: border-box; }
.lt-rows { display: grid; width: 9mm; padding: 1.5mm 0; box-sizing: border-box; }
.lt-ratio { box-sizing: border-box; width: 100%; height: 100%; min-width: 0; min-height: 0; padding: 0; border: 0.2mm solid #cbd5e1; background: #f8fafc; font-size: 6pt; text-align: center; color: #334155; -moz-appearance: textfield; }
.lt-ratio::-webkit-outer-spin-button, .lt-ratio::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.lt-ratio:focus { outline: none; border-color: #2563eb; background: #fff; }
</style>

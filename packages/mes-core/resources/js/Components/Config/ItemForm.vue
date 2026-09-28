<!-- 품목 등록/수정 폼 ( BAS-02 ) — 모달 본체
     - item prop 있으면 수정, 없으면 등록
     - 라우터를 모른다 : 저장 후 onSaved( 저장된 품목 ) 호출 + close ( 닫기·이동은 여는 쪽이 정함 )
     - 탭 : 기본 / 재고·발주 / 단가  ( 추가정보 탭 · 라우팅 · 주 자재(BOM)는 해당 기능 개발 시 추가 )
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span v-if="tabHasError(t.key)" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <!-- 탭 1 · 기본 -->
        <div v-show="tab === 'basic'" class="grid grid-cols-2 gap-x-4 gap-y-3 pt-4">
            <div>
                <label class="block text-gray-500 font-medium mb-1">품번 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.item_code" class="w-full" placeholder="예: PN-A" :disabled="!canWrite" />
                <p v-if="errors.item_code" class="text-xs text-red-500 mt-0.5">{{ errors.item_code[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">품명 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.item_name" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.item_name" class="text-xs text-red-500 mt-0.5">{{ errors.item_name[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">대분류 <span class="text-red-400">*</span></label>
                <BaseSelect v-model="form.item_type" :options="ITEM_TYPES" class="w-full" :disabled="!canWrite" @update:model-value="onTypeChange" />
                <p v-if="errors.item_type" class="text-xs text-red-500 mt-0.5">{{ errors.item_type[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">소분류 <span class="text-red-400">*</span></label>
                <BaseSelect v-model="form.item_kind" :options="kindOptions" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.item_kind" class="text-xs text-red-500 mt-0.5">{{ errors.item_kind[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">단위 <span class="text-red-400">*</span></label>
                <BaseSelect v-model="form.spec_unit" :options="unitOptions" class="w-full" placeholder="단위 선택" :disabled="!canWrite" />
                <p v-if="errors.spec_unit" class="text-xs text-red-500 mt-0.5">{{ errors.spec_unit[0] }}</p>
                <p v-else-if="!unitLoading && !unitOptions.length" class="text-xs text-amber-600 mt-0.5">
                    등록된 단위가 없습니다. 코드관리의 UNIT 그룹에 먼저 추가하세요.
                </p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">규격</label>
                <BaseInput v-model="form.spec" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.spec" class="text-xs text-red-500 mt-0.5">{{ errors.spec[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">
                    기본 라우팅
                    <span v-if="!isEdit" class="text-xs text-gray-400">( 등록 시점에만 자동 적용 )</span>
                </label>
                <BaseSelect v-model="form.routing_idx" :options="routingOptions" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.routing_idx" class="text-xs text-red-500 mt-0.5">{{ errors.routing_idx[0] }}</p>
            </div>
            <div></div>

            <!-- 주 자재 : 가공품은 제품 1 : 원자재 1 이 대부분 → 저장하면 BOM V1 이 만들어진다 -->
            <div class="col-span-2 border-t pt-3">
                <div class="flex items-baseline gap-2 mb-1">
                    <span class="text-gray-500 font-medium">주 자재</span>
                    <span v-if="bomLocked" class="text-xs text-amber-600">BOM 자재가 {{ bomLineCount }}건이라 여기서는 수정할 수 없습니다. BOM관리에서 다루세요.</span>
                    <span v-else class="text-xs text-gray-400">저장하면 BOM {{ mainMaterial.bom_line_idx ? '자재 줄이 수정' : 'V1 이 자동 생성' }}됩니다. 비우면 줄이 삭제됩니다.</span>
                </div>
                <div class="grid grid-cols-3 gap-x-4 gap-y-2">
                    <div class="col-span-2">
                        <BaseSelect v-model="mainMaterial.child_item_idx" :options="materialOptions" class="w-full" placeholder="( 없음 )" :disabled="!canWrite || bomLocked" />
                        <p v-if="errors['main_material.child_item_idx']" class="text-xs text-red-500 mt-0.5">{{ errors['main_material.child_item_idx'][0] }}</p>
                    </div>
                    <div>
                        <BaseInput v-model="mainMaterial.qty" type="number" min="0" class="w-full text-right" placeholder="소요" :disabled="!canWrite || bomLocked || !mainMaterial.child_item_idx" />
                        <p v-if="errors['main_material.qty']" class="text-xs text-red-500 mt-0.5">{{ errors['main_material.qty'][0] }}</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-6 col-span-2 pt-1">
                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                </BaseCheckbox>
                <BaseCheckbox variant="switch" v-model="form.serial_use" true-value="Y" false-value="N" :disabled="!canWrite">
                    시리얼 사용 <span class="text-xs text-gray-400">( 낱개마다 번호 — 중요 제품만 )</span>
                </BaseCheckbox>
            </div>
            <div class="col-span-2">
                <label class="block text-gray-500 font-medium mb-1">비고</label>
                <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
            </div>
        </div>

        <!-- 탭 2 · 재고 · 발주 -->
        <div v-show="tab === 'stock'" class="pt-4">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div v-for="f in STOCK_FIELDS" :key="f.key">
                    <label class="block text-gray-500 font-medium mb-1">
                        {{ f.label }} <span v-if="f.suffix" class="text-xs text-gray-400">( {{ f.suffix }} )</span>
                    </label>
                    <BaseInput
                        v-model="form[f.key]" type="number" min="0" class="w-full text-right"
                        :placeholder="f.nullable ? '미입력' : ''"
                        :empty-value="f.nullable ? null : 0"
                        :disabled="!canWrite"
                    />
                    <p v-if="errors[f.key]" class="text-xs text-red-500 mt-0.5">{{ errors[f.key][0] }}</p>
                </div>
            </div>

            <!-- 배수 · 최소 수량 설명 + 현재 입력값으로 계산한 지시 수량 -->
            <div class="mt-3 px-3 py-2.5 rounded bg-gray-50 text-xs text-gray-600 leading-relaxed">
                <p class="font-semibold text-gray-700 mb-1">배수란?</p>
                <p>
                    발주·작업지시 수량을 <b>몇 개 단위로 맞출지</b> 정하는 값입니다.
                    필요한 수량을 이 값의 배수로 <b>올림</b>합니다. <b>0이면 올림하지 않습니다.</b>
                </p>
                <ul class="mt-1.5 space-y-0.5 list-disc pl-4">
                    <li><b>최소 수량</b> — 한 번에 발주·지시할 수 있는 하한. 필요량이 이보다 적으면 최소 수량까지 올립니다.</li>
                    <li><b>배수</b> — 최소 수량을 적용한 뒤, 이 단위로 올립니다. <span class="text-gray-400">예: 500개 봉지로만 구매 → 500</span></li>
                    <li><b>포장 수량</b> — 한 박스(봉지)에 실제로 담기는 개수. 입고·출하 라벨과 박스 수 계산용이며 발주 수량에는 쓰이지 않습니다.</li>
                </ul>
                <p class="mt-1.5 text-gray-400">
                    포장 단위로만 사고파는 품목은 포장 수량과 배수를 같게 넣습니다.
                    낱개로도 살 수 있으면 배수는 0, 박스(봉지 여러 개) 단위로만 살 수 있으면 박스 수량을 넣습니다.
                </p>
                <p class="mt-2 pt-2 border-t border-gray-200">
                    <span class="text-gray-400 mr-1">현재 입력값으로 계산</span>
                    필요 <b>{{ fmt(PREVIEW_NEED) }}</b>
                    → 최소 {{ fmt(num(form.moq)) }} 적용 <b>{{ fmt(previewMoq) }}</b>
                    → 배수 {{ num(form.unit_round) > 0 ? fmt(num(form.unit_round)) + ' 단위 올림' : '없음' }}
                    → 지시 <b class="text-blue-700">{{ fmt(previewOrder) }}</b>
                </p>
            </div>

            <!-- 소재 항목 : 원소재( STOCK )만 -->
            <template v-if="form.item_kind === 'STOCK'">
                <div class="mt-4 mb-2 text-xs font-semibold text-gray-500 border-b pb-1">소재</div>
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">최소 사용 규격 <span class="text-xs text-gray-400">( 미만은 폐기 대상 )</span></label>
                        <BaseInput
                            v-model="form.min_usable_spec" type="number" min="0" class="w-full text-right"
                            placeholder="미입력"
                            :disabled="!canWrite"
                        />
                        <p v-if="errors.min_usable_spec" class="text-xs text-red-500 mt-0.5">{{ errors.min_usable_spec[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">계산식 코드</label>
                        <BaseInput v-model="form.calc_code" class="w-full" placeholder="예: BAR_CUT" :disabled="!canWrite" />
                        <p v-if="errors.calc_code" class="text-xs text-red-500 mt-0.5">{{ errors.calc_code[0] }}</p>
                    </div>
                </div>
            </template>
        </div>

        <!-- 탭 3 · 단가 -->
        <div v-show="tab === 'price'" class="pt-4">
            <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                <div v-for="f in PRICE_FIELDS" :key="f.key">
                    <label class="block text-gray-500 font-medium mb-1">{{ f.label }}</label>
                    <BaseInput
                        v-model="form[f.key]" type="number" min="0" class="w-full text-right"
                        :empty-value="0"
                        :disabled="!canWrite"
                    />
                    <p v-if="errors[f.key]" class="text-xs text-red-500 mt-0.5">{{ errors[f.key][0] }}</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-400">전표 라인의 기본값입니다. 전표에서 고친 값은 라인에 저장되며, 여기를 바꿔도 기존 전표는 그대로입니다.</p>
        </div>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ canWrite ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" size="sm" :disabled="saving" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { ITEM_TYPES, ITEM_KINDS } from '@core/Components/Config/itemCodes';

const props = defineProps({
    item:     { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 호출 ( 저장된 품목 전달 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.item?.item_idx);

const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'stock', label: '재고·발주' },
    { key: 'price', label: '단가' },
];

const STOCK_FIELDS = [
    // nullable : 빈값이 "미입력" 의미 ( DB NULL 허용 ) → 비워도 0 으로 채우지 않음 ( BaseInput empty-value 미지정 )
    { key: 'pack_qty',         label: '포장 수량',  suffix: '박스당', nullable: true },
    { key: 'safety_stock',     label: '안전재고' },
    { key: 'order_cycle_days', label: '발주 주기',  suffix: '일' },
    { key: 'moq',              label: '최소 수량' },
    { key: 'unit_round',       label: '배수',       suffix: '이 배수로 올림' },
];

const PRICE_FIELDS = [
    { key: 'std_sale_price',   label: '판매단가' },
    { key: 'std_buy_price',    label: '발주단가' },
    { key: 'std_subcon_price', label: '외주단가' },
];

// 오류 필드 → 탭 ( 저장 실패 시 해당 탭으로 이동 )
const FIELD_TAB = {
    ...Object.fromEntries(STOCK_FIELDS.map(f => [f.key, 'stock'])),
    min_usable_spec: 'stock', calc_code: 'stock', spec_dim: 'stock',
    ...Object.fromEntries(PRICE_FIELDS.map(f => [f.key, 'price'])),
};
const tabOf = (field) => FIELD_TAB[field] ?? 'basic';   // routing_idx · main_material.* 는 기본 탭

const tab = ref('basic');

// ---- 폼 ----
const src = props.item ?? {};
const form = reactive({
    item_idx:         src.item_idx ?? null,
    item_code:        src.item_code ?? '',
    item_name:        src.item_name ?? '',
    item_type:        src.item_type ?? 'PRODUCT',
    item_kind:        src.item_kind ?? 'FINISHED',
    spec_unit:        src.spec_unit ?? 'EA',
    spec:             src.spec ?? '',
    serial_use:       src.serial_use ?? 'N',
    pack_qty:         src.pack_qty ?? '',
    safety_stock:     src.safety_stock ?? 0,
    order_cycle_days: src.order_cycle_days ?? 0,
    moq:              src.moq ?? 0,
    unit_round:       src.unit_round ?? 0,
    min_usable_spec:  src.min_usable_spec ?? '',
    calc_code:        src.calc_code ?? '',
    std_sale_price:   src.std_sale_price ?? 0,
    std_buy_price:    src.std_buy_price ?? 0,
    std_subcon_price: src.std_subcon_price ?? 0,
    is_use:           src.is_use ?? 'Y',
    remark:           src.remark ?? '',
    routing_idx:      src.routing_idx ?? '',
});

// ---- 기본 라우팅 · 주 자재 ( BOM ) ----
const routings     = ref([]);
const materials    = ref([]);
const bomLineCount = ref(0);
const mainMaterial = reactive({ bom_line_idx: null, child_item_idx: '', qty: '', calc_code: null, ext_n01: null });

// BOM 자재가 2건 이상이면 주 자재 칸은 읽기 전용 ( BOM관리에서 다룬다 )
const bomLocked = computed(() => bomLineCount.value >= 2);

const routingOptions = computed(() => [
    { value: '', label: '( 없음 )' },
    ...routings.value.map(r => ({ value: r.routing_idx, label: `${r.routing_name} [${r.routing_code}]${r.is_default === 'Y' ? ' · 기본' : ''}` })),
]);
const materialOptions = computed(() => [
    { value: '', label: '( 없음 )' },
    ...materials.value.map(m => ({ value: m.item_idx, label: `${m.item_name} [${m.item_code}]` })),
]);

async function loadFormData() {
    try {
        const { data } = await window.axios.get(route('config.item.formData'), {
            params: form.item_idx ? { item_idx: form.item_idx } : {},
        });
        routings.value     = data.routings ?? [];
        materials.value    = data.materials ?? [];
        bomLineCount.value = data.bom_line_count ?? 0;

        if (data.main_material) {
            Object.assign(mainMaterial, data.main_material);
        }
        // 신규 등록은 기본 라우팅을 자동 선택 ( 등록 시점에만. 수정 화면에서는 건드리지 않는다 )
        if (!isEdit.value && !form.routing_idx && data.default_routing_idx) {
            form.routing_idx = data.default_routing_idx;
        }
    } catch (e) {
        errorMsg.value = '라우팅 · 자재 목록을 불러오지 못했습니다.';
    }
}

onMounted(loadFormData);

const kindOptions = computed(() => ITEM_KINDS[form.item_type] ?? []);

// 대분류 변경 → 소분류가 맞지 않으면 첫 항목으로
function onTypeChange(type) {
    const kinds = ITEM_KINDS[type] ?? [];
    if (!kinds.some(k => k.value === form.item_kind)) {
        form.item_kind = kinds[0]?.value ?? '';
    }
}

// ---- 단위 ( 코드 마스터 UNIT ) ----
const { options: codeOptions, loading: unitLoading } = useCommonCode(['UNIT']);
const unitOptions = computed(() => {
    const opts = codeOptions('UNIT');
    // 기존 품목의 단위가 미사용으로 내려가 목록에 없으면 현재 값은 보이도록 추가
    if (form.spec_unit && !unitLoading.value && !opts.some(o => o.value === form.spec_unit) && isEdit.value) {
        return [...opts, { value: form.spec_unit, label: `${form.spec_unit} ( 미사용 )` }];
    }
    return opts;
});

// ---- 지시 수량 미리보기 ----
const PREVIEW_NEED = 1400;
const num = (v) => (v === '' || v === null || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => Number(v).toLocaleString();
const previewMoq   = computed(() => Math.max(PREVIEW_NEED, num(form.moq)));
const previewOrder = computed(() => {
    const round = num(form.unit_round);
    return round > 0 ? Math.ceil(previewMoq.value / round) * round : previewMoq.value;
});

// ---- 저장 ----
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const tabHasError = (key) => Object.keys(errors.value).some(f => tabOf(f) === key);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const payload = {
            ...form,
            routing_idx: form.routing_idx === '' ? null : Number(form.routing_idx),
            // 주 자재를 비우면 null → 서버가 BOM 줄을 삭제한다
            main_material: (!bomLocked.value && mainMaterial.child_item_idx !== '')
                ? {
                    child_item_idx: Number(mainMaterial.child_item_idx),
                    qty: mainMaterial.qty === '' ? null : mainMaterial.qty,
                    calc_code: mainMaterial.calc_code ?? null,
                    ext_n01: mainMaterial.ext_n01 ?? null,
                }
                : null,
        };
        const { data } = await window.axios.post(route('config.item.save'), payload);
        props.onSaved?.(data.item, data.warnings ?? []);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            // 첫 오류가 있는 탭으로 이동
            const first = Object.keys(errors.value)[0];
            if (first) tab.value = tabOf(first);
        } else if (e.response?.status === 403) {
            errorMsg.value = '저장 권한이 없습니다.';
        } else if (e.response?.status === 404) {
            errorMsg.value = '품목을 찾을 수 없습니다. 목록을 새로고침하세요.';
        } else {
            errorMsg.value = '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

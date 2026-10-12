<!-- 창고관리 ( BAS-06 ) / 기준정보관리 > 창고관리
     - 좌 : 창고 트리 ( 자기참조 계층 · 자재창고 > 1구역 )
     - 우 : 선택한 창고 상세 ( 코드 · 명칭 · 상위 · 정렬 · 사용 )
     - 재고는 잎 노드( 하위 없는 창고 )에 붙는다 → 트리에서 "재고" 표시
     - 삭제 없음 : 사용 여부로 내림. 하위 창고도 함께 내려간다 ( ADR 0019 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">창고관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newWarehouse(null)">+ 등록</BaseButton>
        </div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 트리 -->
            <div class="w-80 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">창고</span>
                    <label class="inline-flex items-center gap-1 text-xs text-gray-500 cursor-pointer select-none">
                        <input type="checkbox" class="base-checkbox w-3.5 h-3.5" v-model="showInactive" />
                        미사용 포함
                    </label>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!tree.length" class="px-3 py-4 text-sm text-gray-400">등록된 창고가 없습니다.</p>
                    <div
                        v-for="w in tree"
                        :key="w.warehouse_idx"
                        class="group flex items-center gap-1 px-2 py-1 min-h-[34px] text-sm transition-colors cursor-pointer"
                        :class="selected?.warehouse_idx === w.warehouse_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        :style="{ paddingLeft: (8 + w._depth * 16) + 'px' }"
                        @click="selectWarehouse(w)"
                    >
                        <span class="text-gray-300">{{ w._hasChildren ? '▾' : '·' }}</span>
                        <span class="truncate" :class="w.is_use === 'N' ? 'text-gray-400 line-through' : ''">{{ w.warehouse_name }}</span>
                        <span class="text-[11px] text-gray-400 shrink-0">[{{ w.warehouse_code }}]</span>
                        <span v-if="w.is_leaf === 'Y'" class="text-[10px] px-1 rounded bg-emerald-50 text-emerald-600 shrink-0" title="하위가 없어 재고가 붙는 창고입니다">재고</span>
                        <span class="flex-1"></span>
                        <!-- 하위 추가 : 평소에는 흐리게, 행에 올리면 뚜렷하게 ( 클릭 영역 24px ) -->
                        <button
                            v-if="canWrite"
                            type="button"
                            class="shrink-0 flex items-center justify-center w-6 h-6 rounded border border-transparent leading-none text-base text-gray-400 opacity-40
                                   transition group-hover:opacity-100 group-hover:border-gray-200 hover:!text-white hover:!bg-blue-600 hover:!border-blue-600"
                            title="이 창고 아래에 하위 창고 추가"
                            @click.stop="newWarehouse(w)"
                        >+</button>
                    </div>
                </div>
            </div>

            <!-- 우 : 상세 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        {{ form.warehouse_idx ? '창고 수정' : '창고 등록' }}
                        <span v-if="parentName" class="text-gray-400 font-normal">[ 상위 · {{ parentName }} ]</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <BaseButton v-if="canDelete && form.warehouse_idx" size="sm" variant="danger" :disabled="saving" @click="removeWarehouse">삭제</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3 max-w-2xl text-sm">
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">창고코드 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.warehouse_code" class="w-full" placeholder="예: WH-A-01" :disabled="!canWrite" />
                            <p v-if="errors.warehouse_code" class="text-xs text-red-500 mt-0.5">{{ errors.warehouse_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">창고명 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.warehouse_name" class="w-full" placeholder="예: 1구역" :disabled="!canWrite" />
                            <p v-if="errors.warehouse_name" class="text-xs text-red-500 mt-0.5">{{ errors.warehouse_name[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">상위 창고</label>
                            <BaseSelect v-model="form.parent_idx" :options="parentOptions" class="w-full" :disabled="!canWrite" />
                            <p v-if="errors.parent_idx" class="text-xs text-red-500 mt-0.5">{{ errors.parent_idx[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">정렬 <span class="text-xs text-gray-400">( 같은 상위 안에서의 순서 )</span></label>
                            <BaseInput v-model="form.sort_order" type="number" min="0" :empty-value="0" class="w-full text-right" :disabled="!canWrite" />
                            <p v-if="errors.sort_order" class="text-xs text-red-500 mt-0.5">{{ errors.sort_order[0] }}</p>
                        </div>
                        <div class="col-span-2 pt-1">
                            <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                                {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                            </BaseCheckbox>
                            <p v-if="form.warehouse_idx && hasChildren(form.warehouse_idx)" class="text-xs text-gray-400 mt-1">
                                하위 창고가 있습니다. 미사용으로 내리면 하위 창고도 함께 내려갑니다.
                            </p>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-gray-500 font-medium mb-1">비고</label>
                            <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
                        </div>
                    </div>
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
import { showAlert } from '@core/Utils/message';

defineOptions({ layout: AppLayout });

// 이 페이지( 창고관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const warehouses   = ref([]);
const selected     = ref(null);
const showInactive = ref(false);
const saving       = ref(false);
const errors       = ref({});

const blankForm = (parentIdx = '') => ({
    warehouse_idx: null, warehouse_code: '', warehouse_name: '',
    parent_idx: parentIdx, sort_order: 0, is_use: 'Y', remark: '',
});
const form = reactive(blankForm());

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 2500);
}

// 평면 목록 → 부모 기준 정렬 + 깊이 ( 미사용 포함 여부 반영 )
const tree = computed(() => {
    const rows = warehouses.value.filter(w => showInactive.value || w.is_use === 'Y');
    const byParent = {};
    const ids = new Set(rows.map(w => w.warehouse_idx));
    for (const w of rows) {
        // 상위가 걸러졌으면 최상위로 올려 보여준다 ( 트리에서 사라지지 않도록 )
        const key = (w.parent_idx && ids.has(w.parent_idx)) ? w.parent_idx : '__root__';
        (byParent[key] ||= []).push(w);
    }
    const out = [];
    const walk = (parent, depth) => {
        for (const w of (byParent[parent] || [])) {
            out.push({ ...w, _depth: depth, _hasChildren: !!(byParent[w.warehouse_idx] || []).length });
            walk(w.warehouse_idx, depth + 1);
        }
    };
    walk('__root__', 0);
    return out;
});

const hasChildren = (idx) => warehouses.value.some(w => w.parent_idx === idx);

// 상위 창고 선택지 : 자기 자신과 자기 하위는 제외 ( 순환 방지 )
const parentOptions = computed(() => {
    const blocked = new Set();
    if (form.warehouse_idx) {
        const collect = (idx) => {
            blocked.add(idx);
            warehouses.value.filter(w => w.parent_idx === idx).forEach(c => collect(c.warehouse_idx));
        };
        collect(form.warehouse_idx);
    }
    return [
        { value: '', label: '( 최상위 )' },
        ...tree.value.filter(w => !blocked.has(w.warehouse_idx))
            .map(w => ({ value: w.warehouse_idx, label: `${'　'.repeat(w._depth)}${w.warehouse_name} [${w.warehouse_code}]` })),
    ];
});

const parentName = computed(() => {
    const idx = Number(form.parent_idx);
    return idx ? (warehouses.value.find(w => w.warehouse_idx === idx)?.warehouse_name ?? '') : '';
});

async function loadWarehouses() {
    try {
        const { data } = await window.axios.get(route('config.warehouse.list'));
        warehouses.value = data.warehouses ?? [];
    } catch (e) {
        showFlash('창고 목록을 불러오지 못했습니다', true);
    }
}

function selectWarehouse(w) {
    selected.value = w;
    errors.value = {};
    Object.assign(form, {
        warehouse_idx: w.warehouse_idx,
        warehouse_code: w.warehouse_code,
        warehouse_name: w.warehouse_name,
        parent_idx: w.parent_idx ?? '',
        sort_order: w.sort_order ?? 0,
        is_use: w.is_use,
        remark: w.remark ?? '',
    });
}

// 신규 ( parent 있으면 그 아래로 )
function newWarehouse(parent) {
    selected.value = null;
    errors.value = {};
    Object.assign(form, blankForm(parent ? parent.warehouse_idx : ''));
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.post(route('config.warehouse.save'), {
            ...form,
            parent_idx: form.parent_idx === '' ? null : Number(form.parent_idx),
        });
        await loadWarehouses();
        const saved = warehouses.value.find(w => w.warehouse_idx === data.warehouse.warehouse_idx);
        if (saved) selectWarehouse(saved);
        showFlash('저장되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422)      { errors.value = e.response.data.errors || {}; showFlash('입력값을 확인하세요', true); }
        else if (status === 403) showFlash('저장 권한이 없습니다.', true);
        else if (status === 404) { showFlash('창고를 찾을 수 없습니다.', true); loadWarehouses(); }
        else                     showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

// 삭제 = 미사용 처리 ( 하위 창고도 함께 )
async function removeWarehouse() {
    if (!form.warehouse_idx) return;

    const childMsg = hasChildren(form.warehouse_idx) ? '\n하위 창고도 함께 미사용 처리됩니다.' : '';
    const result = await showAlert(
        '창고 삭제',
        `[ ${form.warehouse_name} ]을(를) 미사용 처리합니다.${childMsg}\n기존 데이터는 유지되며, 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    saving.value = true;
    try {
        const { data } = await window.axios.post(route('config.warehouse.del'), { warehouse_idxs: [form.warehouse_idx] });
        await loadWarehouses();
        newWarehouse(null);
        selected.value = null;
        // W_WH_HAS_STOCK : 재고가 남은 창고면 함께 알린다 ( 막지 않음 )
        const warn = (data.warnings ?? []).join(' / ');
        showFlash(`${data.changed ?? 1}건이 미사용 처리되었습니다.${warn ? ` ⚠ ${warn}` : ''}`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('창고를 찾을 수 없습니다.', true); loadWarehouses(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

onMounted(loadWarehouses);
</script>

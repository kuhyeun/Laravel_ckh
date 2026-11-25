<!-- 재고관리 품목 LOT 탭 ( 11-23 ) — 재고관리( StockStatus · 화면 옵션 item_tabs )에서 품목 합계 행을 누르면 열리는 탭의 본문
     - 그 품목의 LOT × 창고 ( 전 창고 ) · 도구줄 : LOT · 공급처 LOT · Heat No 찾기 · 무재고 포함 · 라벨 미발행 · 엑셀 · 새로고침
     - 거르기 · 페이지는 tab( 부모가 가진 탭 상태 )에 둔다 — 다른 탭에 갔다 와도 그대로 ( 목록은 다시 읽는다 )
     - LOT 행 클릭 → open( 행 ) — 부모가 LOT 입출고 이력 창을 연다 · 처리 뒤 부모가 load() 를 부른다 -->
<template>
    <div class="flex-1 min-h-0 flex flex-col">
        <div class="flex items-center gap-3 px-3 py-2 bg-gray-50/70 shrink-0 flex-wrap">
            <span class="text-sm font-semibold text-gray-700">{{ tab.item.item_code }} {{ tab.item.item_name }}</span>
            <span v-if="tab.item.item_spec" class="text-xs text-gray-500">{{ tab.item.item_spec }}</span>
            <span v-if="tab.item.warehouse_name" class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ tab.item.warehouse_name }}</span>
            <span class="text-xs text-gray-500">합계 <b class="text-blue-700">{{ qtyText(total, tab.item.spec_unit) }}</b> · {{ paging.total.toLocaleString() }} 줄</span>
            <div class="flex-1"></div>
            <BaseInput v-model="tab.keyword" size="md" class="w-44" :placeholder="isProduct ? 'LOT 찾기' : 'LOT · 공급처 LOT · Heat No'" @keyup.enter="loadFirst" />
            <label class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="tab.with_zero" @change="loadFirst" /> 무재고 포함</label>
            <label v-if="labelEnabled" class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="tab.no_label" @change="loadFirst" /> 라벨 미발행</label>
            <BaseButton size="sm" variant="secondary" :disabled="loading" @click="loadFirst">조회</BaseButton>
            <GridSettingButton :grid="grid" />
            <ExcelExportButton :rows="exportRows" :columns="columns" :grid="grid" :filename="`LOT재고_${tab.item.item_code}`" />
        </div>
        <div class="flex-1 min-h-0 flex flex-col">
            <DataGrid ref="grid" :loading="loading" setting-key="itemlot" class="flex-1" :data="rows" :columns="columns" :pagination="paging"
                empty-text="LOT 재고가 없습니다." @row-click="r => emit('open', r)" @page="onPage" @sort="onSort" />
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { qtyText } from '@core/Components/Stock/stockCodes';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';

const props = defineProps({
    tab:          { type: Object,  required: true },    // { item: { item_idx, item_code, … }, keyword, with_zero, no_label, page, sort }
    listRoute:    { type: String,  required: true },
    columns:      { type: Array,   required: true },    // LOT 열 ( 품목 칸은 부모가 뺐다 )
    labelEnabled: { type: Boolean, default: false },
    isProduct:    { type: Boolean, default: false },
});
const emit = defineEmits(['open']);
const { showFlash } = useToast();

const grid    = ref(null);
const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: props.tab.page ?? 1, perPage: 50, total: 0 });
const total   = computed(() => rows.value.reduce((s, r) => s + Number(r.qty || 0), 0));   // 이 페이지 합 ( 한 품목 LOT 은 보통 한 페이지 )

function params(extra = {}) {
    const t = props.tab;
    const p = { group: 'lot', item_idx: t.item.item_idx, with_zero: t.with_zero, no_label: t.no_label, ...extra };
    if (t.item.warehouse_idx) p.exact_warehouse_idx = t.item.warehouse_idx;   // 창고별 재고관리 : 그 창고만
    if (t.keyword) Object.assign(p, { keyword: t.keyword });   // 통합검색 ( LOT · 공급처 LOT · Heat No · 품번 … )
    if (t.sort?.key) Object.assign(p, { sort: t.sort.key, dir: t.sort.dir });
    return p;
}
const latest = useLatest();
async function load() {
    const isLatest = latest.begin();
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(props.listRoute), { params: params({ page: paging.page, perPage: paging.perPage, _t: Date.now() }) });
        if (!isLatest()) return;
        rows.value = data.rows ?? [];
        paging.total = data.total ?? 0; paging.page = data.page ?? 1;
        props.tab.page = paging.page;
    } catch (e) {
        if (isLatest()) showFlash('LOT 재고를 불러오지 못했습니다.', true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
function loadFirst() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { props.tab.sort = { key, dir }; paging.page = 1; load(); }
async function exportRows() {
    const { data } = await window.axios.get(route(props.listRoute), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

onMounted(load);
defineExpose({ load });
</script>

<!-- 재사용 데이터 그리드 ( TUI Grid / useGrid 기반 ) — 화면정의 CMP-01 MesGrid
     - data 를 받아 렌더만 한다 ( 조회는 화면이 한다 ). data 가 바뀌면 resetData
     - 행 클릭 → row-click ( 행 객체 )
     - 오른쪽 클릭 메뉴는 복사( 셀 · 열 · 행 )만 — 내보내기는 ExcelExportButton 하나로 ( 양식 · 서버 페이징 전체를 반영하는 쪽 )
     - 체크박스( options.rowHeaders: 'checkbox' ) 선택이 바뀌면 → select ( 선택된 행 배열 ). 데이터가 바뀌면 [] 로 다시 알린다
     - pagination { page, perPage, total } 을 주면 서버 페이징 : 아래 페이지 바 → page { page, perPage }
       이때 열에 sortable: true 를 주면 그리드가 직접 정렬하지 않고 → sort { key, dir } ( 서버가 정렬 )
     - empty-text : 결과 0건일 때 문구
     - 엑셀은 ExcelExportButton 을 화면의 목록 머리에 둔다 ( columns · data 를 그대로 넘긴다 )
     - 기능은 더하기만 한다 — 새 prop 을 안 쓰는 화면은 예전과 같다 ( 공통컴포넌트 §7 ) -->
<template>
    <div class="flex flex-col min-h-0">
        <div ref="gridContainer" class="flex-1 min-h-0 overflow-hidden"></div>

        <!-- 서버 페이징 바 -->
        <div v-if="pagination" class="flex items-center justify-between gap-3 px-3 py-1.5 border-t text-xs text-gray-600 shrink-0">
            <span>총 <b>{{ total.toLocaleString() }}</b> 건</span>
            <div class="flex items-center gap-1">
                <button type="button" class="pg-btn" :disabled="current <= 1" @click="go(1)">«</button>
                <button type="button" class="pg-btn" :disabled="current <= 1" @click="go(current - 1)">‹</button>
                <button
                    v-for="p in pageNumbers"
                    :key="p"
                    type="button"
                    class="pg-btn"
                    :class="p === current ? 'bg-blue-600 text-white border-blue-600' : ''"
                    @click="go(p)"
                >{{ p }}</button>
                <button type="button" class="pg-btn" :disabled="current >= lastPage" @click="go(current + 1)">›</button>
                <button type="button" class="pg-btn" :disabled="current >= lastPage" @click="go(lastPage)">»</button>
            </div>
            <select class="border border-gray-300 rounded px-1 py-0.5" :value="perPage" @change="e => changePerPage(Number(e.target.value))">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}개씩</option>
            </select>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useGrid } from '@core/Composables/useGrid';

const PER_PAGE_OPTIONS = [20, 50, 100, 200];

const props = defineProps({
    data:       { type: Array,   default: () => [] },
    columns:    { type: Array,   required: true },
    options:    { type: Object,  default: () => ({}) },
    pagination: { type: Object,  default: null },    // { page, perPage, total } — 있으면 서버 페이징
    emptyText:  { type: String,  default: '' },
});

const emit = defineEmits(['row-click', 'grid-mounted', 'select', 'page', 'sort']);

const gridContainer = ref(null);
const { gridInstance, setGrid, autoResizeGrid } = useGrid();

// 오른쪽 클릭 메뉴 : 그리드 기본 메뉴에서 내보내기( TXT · CSV · 엑셀 )를 뺀 복사만
//   기본 내보내기는 양식을 모르고, 서버 페이징이면 현재 페이지만 나가며, 엑셀은 SheetJS 가 없어 동작하지 않는다
const copyOnlyContextMenu = () => [[
    { name: 'copy',        label: '복사',    action: 'copy' },
    { name: 'copyColumns', label: '열 복사', action: 'copyColumns' },
    { name: 'copyRows',    label: '행 복사', action: 'copyRows' },
]];

defineExpose({ gridInstance });

function emitSelect() {
    emit('select', gridInstance.value?.getCheckedRows() ?? []);
}

onMounted(() => {
    setGrid(gridContainer, {
        // useGrid 기본값은 scroll 이 꺼져 있어( scrollX/Y:false ) 여기서 켠다.
        // bodyHeight:'fitToParent' → 부모(컨테이너) 높이에 맞추고 넘치면 내부 스크롤
        scrollX: true,
        scrollY: true,
        bodyHeight: 'fitToParent',
        columns: props.columns,
        contextMenu: copyOnlyContextMenu,   // 화면이 options.contextMenu 로 바꿀 수 있다
        data: props.data ?? [],
        ...props.options,
    });
    autoResizeGrid(gridContainer, gridInstance.value);
    applyEmptyText();

    const grid = gridInstance.value;

    grid?.on('click', (ev) => {
        if (ev.rowKey == null) return;   // 헤더 · 빈 영역
        // 행 헤더( _checked 체크박스, _number 등 ) 클릭은 무시
        if (ev.columnName?.startsWith('_')) return;
        const row = grid.getRow(ev.rowKey);
        if (row) emit('row-click', row);
    });

    ['check', 'uncheck', 'checkAll', 'uncheckAll'].forEach(ev => grid?.on(ev, emitSelect));

    // 서버 페이징이면 정렬도 서버가 한다 ( 그리드의 자체 정렬을 막고 알리기만 )
    grid?.on('beforeSort', (ev) => {
        if (!props.pagination) return;
        ev.stop();
        emit('sort', { key: ev.columnName, dir: ev.ascending ? 'asc' : 'desc' });
    });

    emit('grid-mounted', grid);
});

// 외부 data 변경 → 그리드 갱신 ( 체크가 풀리므로 선택도 비었다고 알린다 )
watch(() => props.data, (next) => {
    gridInstance.value?.resetData(next ?? []);
    emit('select', []);
});

// ---- 빈 결과 문구 ----
function applyEmptyText() {
    if (!props.emptyText || !gridContainer.value) return;
    gridContainer.value.style.setProperty('--mes-grid-empty', JSON.stringify(props.emptyText));
    gridContainer.value.classList.add('mes-grid-has-empty');
}

// ---- 서버 페이징 ----
const current  = computed(() => Number(props.pagination?.page) || 1);
const perPage  = computed(() => Number(props.pagination?.perPage) || 50);
const total    = computed(() => Number(props.pagination?.total) || 0);
const lastPage = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));
// 현재 페이지 주변 5개
const pageNumbers = computed(() => {
    const start = Math.max(1, Math.min(current.value - 2, lastPage.value - 4));
    return Array.from({ length: Math.min(5, lastPage.value) }, (_, i) => start + i);
});

function go(page) {
    if (page < 1 || page > lastPage.value || page === current.value) return;
    emit('page', { page, perPage: perPage.value });
}
function changePerPage(n) {
    emit('page', { page: 1, perPage: n });
}
</script>

<style scoped>
.pg-btn {
    min-width: 26px;
    padding: 2px 6px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: #fff;
}
.pg-btn:disabled { opacity: .4; cursor: default; }
.pg-btn:not(:disabled):hover { background: #eff6ff; }


/* 빈 결과 문구 ( TUI 기본 문구를 바꾼다 ) */
.mes-grid-has-empty :deep(.tui-grid-layer-state-content p) { font-size: 0; }
.mes-grid-has-empty :deep(.tui-grid-layer-state-content p)::after {
    content: var(--mes-grid-empty);
    font-size: 13px;
}
</style>

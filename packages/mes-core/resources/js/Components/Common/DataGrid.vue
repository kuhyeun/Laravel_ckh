<!-- 재사용 데이터 그리드 ( TUI Grid / useGrid 기반 ) — 화면정의 CMP-01 MesGrid
     - data 를 받아 렌더만 한다 ( 조회는 화면이 한다 ). data 가 바뀌면 resetData
     - 행 클릭 → row-click ( 행 객체 )
     - 오른쪽 클릭 메뉴는 복사( 셀 · 열 · 행 )만 — 내보내기는 ExcelExportButton 하나로 ( 양식 · 서버 페이징 전체를 반영하는 쪽 )
     - 체크박스( options.rowHeaders: 'checkbox' ) 선택이 바뀌면 → select ( 선택된 행 배열 ). 데이터가 바뀌면 [] 로 다시 알린다
     - pagination { page, perPage, total } 을 주면 서버 페이징 : 아래 페이지 바 → page { page, perPage }
       이때 열에 sortable: true 를 주면 그리드가 직접 정렬하지 않고 → sort { key, dir } ( 서버가 정렬 )
     - empty-text : 결과 0건일 때 문구
     - 트리( options.treeColumnOptions ) : 하위 행이 있는 행은 트리 칸을 누르면 펼침 / 접기 ( row-click 대신 ). 아이콘은 그리드가 처리
       빈 _children( [] )은 지운다 — 남아 있으면 하위 행이 없는데도 펼침 아이콘이 생긴다
     - 엑셀은 ExcelExportButton 을 화면의 목록 머리에 둔다 ( columns · data 를 그대로 넘긴다 )
     - setting-key : 사용자별 열 설정( 표시 · 이름 · 순서 · 너비 — 사용자 × 화면 × 키 ) 켜기. 오른쪽 클릭 "열 설정…" · GridSettingButton
       열에 lockHide: true 를 주면 숨길 수 없다 / 끌어서 바꾼 너비는 저장된다 / 엑셀은 ExcelExportButton :grid 로 이 설정을 따른다
     - active-field · active-key : 지금 고른 행 하나만 색칠( row-selected ) — 목록 · 상세가 나란한 화면 ( 데이터가 바뀌어도 다시 찾아 칠한다 )
     - loading : 조회 중이면 그리드 위를 덮고 "불러오는 중…" ( 0.3 초가 넘을 때만 — 짧은 조회는 깜빡이지 않게 · useDelayedBusy )
     - 기능은 더하기만 한다 — 새 prop 을 안 쓰는 화면은 예전과 같다 ( 공통컴포넌트 §7 ) -->
<template>
    <div class="flex flex-col min-h-0">
        <!-- 그리드 자리 : TUI 의 fitToParent 는 그리드 요소의 바로 위 부모 높이에 맞춘다 → 페이징 바를 뺀 이 칸만 부모가 되게 감싼다 -->
        <div class="relative flex-1 min-h-0">
            <div ref="gridContainer" class="absolute inset-0 overflow-hidden"></div>
            <!-- 조회 중 : 앞 결과를 가리고 알림 ( 끝나면 새 목록 ) -->
            <div v-if="showLoading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/75" aria-live="polite">
                <div class="flex items-center gap-2 px-4 py-2 rounded-md border border-gray-200 bg-white shadow-sm text-sm text-gray-600">
                    <svg class="w-4 h-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                    불러오는 중…
                </div>
            </div>
        </div>

        <!-- 서버 페이징 바 -->
        <div v-if="pagination" class="flex items-center justify-between gap-4 px-4 py-2.5 border-t text-sm text-gray-600 shrink-0">
            <span>총 <b>{{ total.toLocaleString() }}</b> 건</span>
            <div class="flex items-center gap-1.5">
                <button type="button" class="pg-btn" :disabled="current <= 1" @click="go(1)">«</button>
                <button type="button" class="pg-btn" :disabled="current <= 1" @click="go(current - 1)">‹</button>
                <button
                    v-for="p in pageNumbers"
                    :key="p"
                    type="button"
                    class="pg-btn"
                    :class="{ 'is-current': p === current }"
                    @click="go(p)"
                >{{ p }}</button>
                <button type="button" class="pg-btn" :disabled="current >= lastPage" @click="go(current + 1)">›</button>
                <button type="button" class="pg-btn" :disabled="current >= lastPage" @click="go(lastPage)">»</button>
            </div>
            <select class="h-8 border border-gray-300 rounded px-2 text-sm" :value="perPage" @change="e => changePerPage(Number(e.target.value))">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}개씩</option>
            </select>
        </div>
    </div>
</template>

<script setup>
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useGrid } from '@core/Composables/useGrid';
import { useDelayedBusy } from '@core/Composables/useDelayedBusy';
import { useModalStore } from '@core/Stores/modalStore';
import GridColumnSettings from '@core/Components/Common/GridColumnSettings.vue';
import { applyGridSetting, toSetting } from '@core/Utils/gridSetting';

const PER_PAGE_OPTIONS = [20, 50, 100, 200];

const props = defineProps({
    data:       { type: Array,   default: () => [] },
    columns:    { type: Array,   required: true },
    options:    { type: Object,  default: () => ({}) },
    pagination: { type: Object,  default: null },    // { page, perPage, total } — 있으면 서버 페이징
    emptyText:  { type: String,  default: '' },
    settingKey: { type: String,  default: '' },      // 있으면 사용자별 열 설정 ( 한 화면에 그리드가 여럿이면 다르게 )
    activeField: { type: String, default: '' },      // 지금 고른 행 표시 : row[activeField] === activeKey 인 행 하나만 색칠 ( row-selected )
    activeKey:   { type: [String, Number], default: null },
    loading:     { type: Boolean, default: false },   // 조회 중 표시
});

const emit = defineEmits(['row-click', 'grid-mounted', 'select', 'page', 'sort']);

const gridContainer = ref(null);
const showLoading   = useDelayedBusy(() => props.loading);
const { gridInstance, setGrid, autoResizeGrid } = useGrid();

// 오른쪽 클릭 메뉴 : 그리드 기본 메뉴에서 내보내기( TXT · CSV · 엑셀 )를 뺀 복사만
//   기본 내보내기는 양식을 모르고, 서버 페이징이면 현재 페이지만 나간다
//   ( tui-grid 가 끌고 오는 xlsx 는 취약점 때문에 빈 패키지로 바꿨다 — src/vendor-stubs/xlsx. 기본 엑셀 내보내기는 켜도 동작하지 않는다 )
const copyOnlyContextMenu = () => [[
    { name: 'copy',        label: '복사',    action: 'copy' },
    { name: 'copyColumns', label: '열 복사', action: 'copyColumns' },
    { name: 'copyRows',    label: '행 복사', action: 'copyRows' },
]];

// ---- 열 설정 ( setting-key ) ----
const page        = usePage();
const modalStore  = useModalStore();
const gridColumns = shallowRef(props.columns);   // 그리드에 준 열 ( 설정 적용 ) — 엑셀도 이것을 쓴다 ( 열 객체는 반응형으로 감싸지 않는다 — TUI 에 그대로 )
const settable    = () => gridColumns.value.filter(c => c._origHeader !== undefined);
const settingParams = () => ({ page: page.component, key: props.settingKey });
// 설정에 쓰는 화면 열 : 트리 펼침 열은 숨기면 트리가 깨지므로 늘 잠근다
const baseColumns = () => {
    const tree = props.options?.treeColumnOptions?.name;
    return tree ? props.columns.map(c => c.name === tree ? { ...c, lockHide: true } : c) : props.columns;
};

async function loadSetting() {
    try {
        const { data } = await window.axios.get(route('common.gridSetting.show'), { params: settingParams() });
        return data.columns ?? null;
    } catch (e) {
        return null;   // 못 읽으면 기본 구성
    }
}
function applyColumns(cols) {
    gridColumns.value = cols;
    gridInstance.value?.setColumns(cols);
}
async function saveSetting(list) {
    await window.axios.post(route('common.gridSetting.save'), { ...settingParams(), columns: list });
}

function openSettings() {
    if (!props.settingKey) return;
    modalStore.open(GridColumnSettings, {
        modalTitle: '열 설정',
        columns: settable(),
        onApply: async (list) => {
            const widths = Object.fromEntries(settable().map(c => [c.name, c._savedWidth]));
            const saved  = list.map(l => ({ ...l, width: widths[l.name] ?? null }));
            await saveSetting(saved);
            applyColumns(applyGridSetting(baseColumns(), saved));
        },
        onReset: async () => {
            await window.axios.post(route('common.gridSetting.reset'), settingParams());
            applyColumns(applyGridSetting(baseColumns(), null));
        },
    }, { size: 'md' });
}

// 끌어서 바꾼 너비 : 잠깐 모았다가 저장
let widthTimer = null;
function onColumnResize(ev) {
    (ev.resizedColumns ?? []).forEach(({ columnName, width }) => {
        const c = gridColumns.value.find(x => x.name === columnName && x._origHeader !== undefined);
        if (c) { c._savedWidth = Math.round(width); c.width = Math.round(width); }
    });
    clearTimeout(widthTimer);
    widthTimer = setTimeout(() => saveSetting(toSetting(gridColumns.value)).catch(() => {}), 800);
}
onBeforeUnmount(() => clearTimeout(widthTimer));

// 칸이 아닌 곳( 그리드 바깥 · 그리드 안 빈 곳 · 머리 )을 누르면 칸 포커스 · 선택 테두리를 뺀다
//   오른쪽 클릭 메뉴( 복사는 포커스 칸을 쓴다 ) · 편집기 · 그리드 달력은 그대로 둔다
const KEEP_FOCUS = 'td.tui-grid-cell, .tui-grid-context-menu, .tui-grid-layer-editing, .tui-datepicker';
function onDocPointerDown(ev) {
    const grid = gridInstance.value;
    if (!grid || !gridContainer.value) return;
    const t = ev.target;
    if (gridContainer.value.contains(t) && t.closest?.(KEEP_FOCUS)) return;
    if (t.closest?.('.tui-grid-context-menu, .tui-datepicker')) return;   // 메뉴 · 달력이 body 로 나가 있을 때
    // TUI 가 빈 곳( cell-dummy )을 눌렀을 때 하는 순서와 같다 — 편집 저장 → 포커스 → 끌어 고른 범위 ( 범위는 공개 API 가 없어 dispatch )
    grid.dispatch?.('saveAndFinishEditing');
    grid.blur();
    grid.dispatch?.('initSelection');
}
onMounted(() => document.addEventListener('pointerdown', onDocPointerDown, true));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocPointerDown, true));

const exportColumns = computed(() => gridColumns.value);

defineExpose({ gridInstance, openSettings, exportColumns });

// 빈 _children 은 지운다 ( 아래 단계까지 )
function treeRows(rows) {
    return (rows ?? []).map(r => {
        if (!Array.isArray(r._children)) return r;
        if (!r._children.length) {
            const { _children, ...rest } = r;
            return rest;
        }
        return { ...r, _children: treeRows(r._children) };
    });
}

function emitSelect() {
    emit('select', gridInstance.value?.getCheckedRows() ?? []);
}

onMounted(async () => {
    if (props.settingKey) {
        const saved = await loadSetting();
        if (!gridContainer.value) return;   // 읽는 사이 화면을 떠났다
        gridColumns.value = applyGridSetting(baseColumns(), saved);
    }
    setGrid(gridContainer, {
        // useGrid 기본값은 scroll 이 꺼져 있어( scrollX/Y:false ) 여기서 켠다.
        // bodyHeight:'fitToParent' → 부모(컨테이너) 높이에 맞추고 넘치면 내부 스크롤
        scrollX: true,
        scrollY: true,
        bodyHeight: 'fitToParent',
        columns: gridColumns.value,
        contextMenu: props.settingKey
            ? () => [...copyOnlyContextMenu(), [{ name: 'columnSetting', label: '열 설정…', action: openSettings }]]
            : copyOnlyContextMenu,   // 화면이 options.contextMenu 로 바꿀 수 있다
        // 정렬은 sortable: true 를 준 열만. useGrid 기본값( false )이면 TUI 가 DataSource( 서버 API ) 경로로 가서 오류가 난다
        //   서버 페이징이면 beforeSort 에서 막고 sort 이벤트로 알린다 ( 이 이벤트는 client 경로에서만 나온다 )
        useClientSort: true,
        data: treeRows(props.data),
        ...props.options,
    });
    autoResizeGrid(gridContainer, gridInstance.value);
    applyEmptyText();

    const grid = gridInstance.value;

    const treeColumn = props.options?.treeColumnOptions?.name;
    grid?.on('click', (ev) => {
        if (ev.rowKey == null) return;   // 헤더 · 빈 영역
        // 행 헤더( _checked 체크박스, _number 등 ) 클릭은 무시
        if (ev.columnName?.startsWith('_')) return;
        // 트리 칸 : 하위 행이 있으면 펼침 / 접기 ( 아이콘을 누른 것은 그리드가 이미 처리 )
        if (treeColumn && ev.columnName === treeColumn && grid.getChildRows(ev.rowKey)?.length) {
            if (!ev.nativeEvent?.target?.closest?.('.tui-grid-btn-tree')) {
                // 펼침 상태는 _attributes.tree.expanded ( TUI tree.isExpanded )
                grid.getRow(ev.rowKey)?._attributes?.tree?.expanded ? grid.collapse(ev.rowKey) : grid.expand(ev.rowKey);
            }
            return;
        }
        if (ev.nativeEvent?.target?.closest?.('.tui-grid-btn-tree')) return;
        const row = grid.getRow(ev.rowKey);
        if (row) emit('row-click', row);
    });

    ['check', 'uncheck', 'checkAll', 'uncheckAll'].forEach(ev => grid?.on(ev, emitSelect));
    if (props.settingKey) grid?.on('columnResize', onColumnResize);

    // 서버 페이징이면 정렬도 서버가 한다 ( 그리드의 자체 정렬을 막고 알리기만 )
    //   막으면 그리드의 정렬 상태가 그대로라 ascending 이 늘 같은 값으로 온다 → 방향은 여기서 기억해 번갈아 보낸다
    grid?.on('beforeSort', (ev) => {
        if (!props.pagination) return;
        ev.stop();
        const dir = (serverSort.key === ev.columnName && serverSort.dir === 'asc') ? 'desc' : 'asc';
        serverSort.key = ev.columnName;
        serverSort.dir = dir;
        emit('sort', { key: ev.columnName, dir });
    });

    paintActive();
    emit('grid-mounted', grid);
});

// 외부 data 변경 → 그리드 갱신 ( 체크가 풀리므로 선택도 비었다고 알린다 )
watch(() => props.data, (next) => {
    gridInstance.value?.resetData(treeRows(next));
    activeRowKey = null;   // resetData 가 행 클래스를 지웠다
    paintActive();
    emit('select', []);
});

// ---- 지금 고른 행 ( active-field · active-key ) ----
let activeRowKey = null;
function paintActive() {
    const grid = gridInstance.value;
    if (!grid || !props.activeField) return;
    if (activeRowKey !== null) grid.removeRowClassName(activeRowKey, 'row-selected');
    const hit = props.activeKey === null || props.activeKey === ''
        ? null
        : grid.findRows(r => String(r[props.activeField]) === String(props.activeKey))[0];
    activeRowKey = hit ? hit.rowKey : null;
    if (activeRowKey !== null) grid.addRowClassName(activeRowKey, 'row-selected');
}
watch(() => props.activeKey, paintActive);

// ---- 빈 결과 문구 ----
function applyEmptyText() {
    if (!props.emptyText || !gridContainer.value) return;
    gridContainer.value.style.setProperty('--mes-grid-empty', JSON.stringify(props.emptyText));
    gridContainer.value.classList.add('mes-grid-has-empty');
}

// ---- 서버 페이징 ----
const serverSort = { key: '', dir: '' };   // 서버 정렬 마지막 열 · 방향
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
    min-width: 34px;
    height: 32px;
    padding: 0 10px;
    border: 1px solid theme('colors.gray.300');
    border-radius: 4px;
    background: theme('colors.white');
}
.pg-btn:disabled { opacity: .4; cursor: default; }
.pg-btn:not(:disabled):hover { background: theme('colors.blue.50'); }
/* 현재 페이지 ( scoped 배경이 Tailwind 클래스를 이기므로 여기서 칠한다 ) */
.pg-btn.is-current,
.pg-btn.is-current:hover { background: theme('colors.blue.600'); border-color: theme('colors.blue.600'); color: theme('colors.white'); }


/* 빈 결과 문구 ( TUI 기본 문구를 바꾼다 ) */
.mes-grid-has-empty :deep(.tui-grid-layer-state-content p) { font-size: 0; }
.mes-grid-has-empty :deep(.tui-grid-layer-state-content p)::after {
    content: var(--mes-grid-empty);
    font-size: 13px;
}
</style>

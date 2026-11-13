<!-- 활동 로그 ( 설정 > 활동 로그 · 11-12 — 회의 '모든 페이지 LOG : 어떤 메뉴에서 어떤 활동을 누가 했는지' ) — 조회 전용
     - 한 행 = 요청 하나 ( 저장 · 삭제 · 취소 … · 화면 열기 · 엑셀 내보내기 · 로그인 · 로그인 실패 · 로그아웃 ) — 서버 ActivityLogger 가 쌓는다
     - 거르기 : 기간 · 사용자 · 활동 종류 · 결과 / 검색 : 사용자 · 메뉴 · 활동 · 대상 · 주소
     - 행을 누르면 그 요청의 보낸 값( 비밀번호 등은 가림 ) · 주소 · 브라우저 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar v-model="search" :date-bases="[{ value: 'logged', label: '일시' }]" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <BaseSelect v-model="search.account_idx" :options="userOptions" class="w-44" />
            <BaseSelect v-model="search.action" :options="actionOptions" class="w-36" />
            <BaseSelect v-model="search.is_success" :options="RESULT_OPTIONS" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex gap-2">
            <div class="flex-[3] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">활동 로그 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 · 행을 누르면 보낸 값</span></span>
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="활동로그" />
                    </div>
                </div>
                <DataGrid ref="mainGrid" :loading="loading" setting-key="main" class="flex-1" :data="rows" :columns="columns" :pagination="paging"
                    empty-text="조건에 맞는 활동이 없습니다." @row-click="openDetail" @page="onPage" @sort="onSort" />
            </div>

            <!-- 상세 -->
            <div class="flex-1 min-w-[320px] border border-gray-200 flex flex-col min-h-0">
                <div class="px-3 py-2 bg-gray-50/70 text-sm font-semibold text-gray-600 shrink-0">상세</div>
                <p v-if="!detail" class="px-3 py-6 text-center text-xs text-gray-400">{{ detailLoading ? '불러오는 중…' : '왼쪽에서 행을 고르세요.' }}</p>
                <div v-else class="flex-1 min-h-0 overflow-auto px-3 py-2 text-xs space-y-1.5">
                    <p><span class="text-gray-400">일시</span> {{ detail.logged_at }}</p>
                    <p><span class="text-gray-400">사용자</span> {{ detail.user }}</p>
                    <p><span class="text-gray-400">메뉴</span> {{ detail.menu_path || '—' }}</p>
                    <p><span class="text-gray-400">활동</span> {{ detail.action_name }}<template v-if="detail.target"> · <b>{{ detail.target }}</b></template></p>
                    <p><span class="text-gray-400">결과</span>
                        <span :class="detail.is_success === 'Y' ? 'text-green-700' : 'text-red-600 font-semibold'">{{ detail.is_success === 'Y' ? '성공' : '실패' }}</span>
                        <span class="text-gray-400"> · {{ detail.status_code }}</span>
                        <template v-if="detail.message"> · <span class="text-red-600">{{ detail.message }}</span></template>
                    </p>
                    <p class="break-all"><span class="text-gray-400">요청</span> {{ detail.method }} {{ detail.url }}<span v-if="detail.route_name" class="text-gray-400"> ( {{ detail.route_name }} )</span></p>
                    <p class="break-all"><span class="text-gray-400">접속</span> {{ detail.ip }} · <span class="text-gray-500">{{ detail.user_agent }}</span></p>
                    <div class="pt-1">
                        <p class="text-gray-400 mb-0.5">보낸 값</p>
                        <pre v-if="detail.payload" class="p-2 rounded bg-gray-50 border border-gray-100 text-[11px] whitespace-pre-wrap break-all">{{ typeof detail.payload === 'string' ? detail.payload : JSON.stringify(detail.payload, null, 2) }}</pre>
                        <p v-else class="text-gray-400">없음</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { useToast } from '@core/Composables/useToast';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);
const { showFlash } = useToast();

const SEARCH_FIELDS = [
    { value: 'user', label: '사용자' }, { value: 'menu', label: '메뉴' }, { value: 'action', label: '활동' },
    { value: 'target', label: '대상' }, { value: 'url', label: '주소 · 요청' },
];
const RESULT_OPTIONS = [{ value: '', label: '결과 전체' }, { value: 'Y', label: '성공' }, { value: 'N', label: '실패' }];
const blankFilters = () => ({ account_idx: '', action: '', is_success: '' });
const search = reactive({ date_basis: 'logged', from: '', to: '', keyword: '', ...blankFilters() });

const users   = ref([]);
const actions = ref([]);
const userOptions   = computed(() => [{ value: '', label: '사용자 전체' }, ...users.value]);
const actionOptions = computed(() => [{ value: '', label: '활동 전체' }, ...actions.value]);

// 활동 종류 색 ( 변경은 진하게 · 보기는 옅게 )
const ACTION_COLOR = { DELETE: COLORS.red600, CANCEL: COLORS.amber700, LOGIN_FAIL: COLORS.red600, VIEW: COLORS.gray500, EXPORT: COLORS.gray500, LOGIN: COLORS.blue600, LOGOUT: COLORS.gray500 };
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const columns = [
    { header: '일시', name: 'logged_at', width: 150, align: 'center', sortable: true },
    { header: '사용자', name: 'user_name', width: 140, sortable: true, formatter: ({ row }) => row.user_name ? `${esc(row.user_name)} <span style="color:${COLORS.gray400}">${esc(row.user_id)}</span>` : esc(row.user_id) },
    { header: '메뉴', name: 'menu_path', minWidth: 250, sortable: true, escapeHTML: true },
    { header: '활동', name: 'action_name', width: 150, sortable: true, formatter: ({ row }) => `<span style="color:${ACTION_COLOR[row.action] ?? COLORS.gray800};font-weight:600">${esc(row.action_name)}</span>` },
    { header: '대상', name: 'target', width: 170, sortable: true, escapeHTML: true },
    { header: '결과', name: 'is_success', width: 70, align: 'center', sortable: true,
      formatter: ({ value }) => value === 'Y' ? `<span style="color:${COLORS.green600}">성공</span>` : `<span style="color:${COLORS.red600};font-weight:600">실패</span>` },
    { header: '실패 사유', name: 'message', width: 250, escapeHTML: true },
    { header: 'IP', name: 'ip', width: 120, sortable: true },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const { date_basis, ...rest } = search;
    const p = { ...rest, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}
async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('basic.activity.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('활동 로그를 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
async function exportRows() {
    const { data } = await window.axios.get(route('basic.activity.list'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

const detail = ref(null);
const detailLoading = ref(false);
async function openDetail(row) {
    if (!row?.activity_log_idx) return;
    detailLoading.value = true;
    detail.value = null;
    try {
        detail.value = (await window.axios.get(route('basic.activity.show', row.activity_log_idx))).data.log;
    } catch (e) {
        showFlash('상세를 불러오지 못했습니다.', true);
    } finally {
        detailLoading.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('basic.activity.formData'));
        users.value = data.users ?? []; actions.value = data.actions ?? [];
    } catch (e) { /* 거르기 없이도 조회된다 */ }
});
</script>

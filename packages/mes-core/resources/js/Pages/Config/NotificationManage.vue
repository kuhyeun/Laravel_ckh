<!-- 알림관리 ( 기준정보관리 > 알림관리 · 11-13 — 회의 '알람관리 메뉴' ) — 내 알림만 · 조회 + 읽음 표시
     - 지금 쌓이는 알림 : 출하 결재 요청( 승인할 수 있는 사람에게 ) · 승인 · 반려( 요청한 사람에게 )
     - 행을 누르면 읽음 표시 후 그 화면으로 ( 검색어 = 문서 번호 ) · 고른 행 / 모두 읽음
     - 상단 종과 같은 알림 ( 'mes:notifications' 로 종 숫자를 맞춘다 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar v-model="search" :date-bases="[{ value: 'created', label: '알림 일시' }]" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <BaseSelect v-model="search.kind" :options="kindOptions" class="w-36" />
            <BaseSelect v-model="search.read" :options="READ_OPTIONS" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">알림 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 · 안 읽음 {{ unread }} · 행을 누르면 그 화면으로</span></span>
                <div class="flex items-center gap-1.5">
                    <BaseButton variant="secondary" :disabled="!unread" @click="readChecked">고른 행 읽음</BaseButton>
                    <BaseButton variant="secondary" :disabled="!unread" @click="readAll">모두 읽음</BaseButton>
                    <GridSettingButton :grid="mainGrid" />
                </div>
            </div>
            <DataGrid ref="mainGrid" :loading="loading" setting-key="main" class="flex-1" :data="rows" :columns="columns" :pagination="paging" :options="{ rowHeaders: 'checkbox' }"
                empty-text="알림이 없습니다." @row-click="go" @select="(r) => checked = r" @page="onPage" @sort="onSort" />
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { router } from '@inertiajs/vue3';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useToast } from '@core/Composables/useToast';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);
const { showFlash } = useToast();

const SEARCH_FIELDS = [{ value: 'title', label: '제목 · 내용' }, { value: 'no', label: '문서 번호' }, { value: 'actor', label: '보낸 사람' }];
const READ_OPTIONS  = [{ value: '', label: '읽음 전체' }, { value: 'N', label: '안 읽음' }, { value: 'Y', label: '읽음' }];
const blankFilters = () => ({ kind: '', read: '' });
const search = reactive({ date_basis: 'created', from: '', to: '', keyword: '', ...blankFilters() });

const kinds = ref([]);
const kindOptions = computed(() => [{ value: '', label: '종류 전체' }, ...kinds.value]);

const KIND_COLOR = { APPROVAL_REQUEST: COLORS.amber700, APPROVAL_APPROVED: COLORS.green600, APPROVAL_REJECTED: COLORS.red600 };
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const columns = [
    { header: '알림 일시', name: 'created_at', width: 150, align: 'center', sortable: true },
    { header: '종류', name: 'kind', width: 100, align: 'center', sortable: true,
      formatter: ({ row }) => `<span style="color:${KIND_COLOR[row.kind] ?? COLORS.gray800};font-weight:600">${esc(row.kind_name)}</span>` },
    { header: '제목', name: 'title', minWidth: 240, sortable: true,
      formatter: ({ row }) => `<span style="${row.read_at ? '' : 'font-weight:700'}">${esc(row.title)}</span>` },
    { header: '내용', name: 'body', minWidth: 260, escapeHTML: true },
    { header: '보낸 사람', name: 'actor_name', width: 110, align: 'center', sortable: true, escapeHTML: true },
    { header: '읽음', name: 'read_at', width: 150, align: 'center', sortable: true,
      formatter: ({ value }) => value ? esc(value) : `<span style="color:${COLORS.blue600};font-weight:600">안 읽음</span>` },
];

const rows    = ref([]);
const checked = ref([]);
const unread  = ref(0);
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
        const { data } = await window.axios.get(route('common.notifications.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
        setUnread(data.unread ?? 0);
    } catch (e) {
        showFlash('알림을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 종 숫자도 맞춘다
function setUnread(n) {
    unread.value = n;
    window.dispatchEvent(new CustomEvent('mes:notifications', { detail: { unread: n, from: 'manage' } }));
}

async function go(row) {
    if (!row?.notification_idx) return;
    if (!row.read_at) {
        try { setUnread((await window.axios.post(route('common.notifications.read', row.notification_idx))).data.unread ?? unread.value); } catch (e) { /* 이동은 */ }
    }
    if (row.url) router.visit(row.url);
    else { showFlash('이동할 화면이 없거나 그 화면의 권한이 없습니다.', true); load(); }
}

async function markRead(idxs) {
    try {
        const { data } = await window.axios.post(route('common.notifications.readAll'), idxs ? { idxs } : {});
        setUnread(data.unread ?? 0);
        load();
    } catch (e) {
        showFlash('읽음 표시를 하지 못했습니다.', true);
    }
}
function readChecked() {
    const idxs = checked.value.map(r => r.notification_idx).filter(Boolean);
    if (!idxs.length) { showFlash('읽음으로 할 행을 고르세요.', true); return; }
    markRead(idxs);
}
function readAll() { markRead(null); }

onMounted(async () => {
    try {
        kinds.value = (await window.axios.get(route('common.notifications.formData'))).data.kinds ?? [];
    } catch (e) { /* 거르기 없이도 조회된다 */ }
});
</script>

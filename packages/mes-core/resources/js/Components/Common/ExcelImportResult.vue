<!-- 엑셀 일괄 등록 — 결과 목록 모달 ( ExcelImportDialog 의 [결과 목록]이 연다 )
     - 서버의 행 단위 결과( result.rows )를 그대로 그린다 + 올린 파일의 값( upload.rows )을 행 번호로 붙인다
     - 보기 : 전체 / 오류 / 정상. 오류 행은 붉게
     - 엑셀 값은 escapeHTML 로 글자 그대로 보인다 -->
<template>
    <div class="mt-3 text-sm flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3">
            <p class="font-medium" :class="summary.error ? 'text-red-700' : 'text-blue-800'">
                전체 {{ summary.total }}행 · 신규 {{ summary.create }} · 수정 {{ summary.update }}
                <template v-if="summary.error"> · 오류 {{ summary.error }}</template>
            </p>
            <div class="flex items-center gap-1 text-xs">
                <button
                    v-for="f in FILTERS"
                    :key="f.key"
                    type="button"
                    class="px-2.5 py-1 rounded border"
                    :class="filter === f.key ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'"
                    @click="filter = f.key"
                >{{ f.label }} {{ countOf(f.key) }}</button>
            </div>
        </div>

        <div class="h-[60vh] flex flex-col border rounded">
            <DataGrid class="flex-1" :data="gridRows" :columns="columns" empty-text="해당하는 행이 없습니다." />
        </div>

        <div class="flex items-center justify-end gap-2 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';

const props = defineProps({
    layout: { type: Object, required: true },               // 업로드 양식 ( 값 열 머리글 )
    result: { type: Object, required: true },               // 서버 결과 { summary, rows }
    upload: { type: Object, default: null },                // readUpload 결과 ( 올린 값 )
});
defineEmits(['close']);

const FILTERS = [
    { key: 'all',   label: '전체' },
    { key: 'error', label: '오류' },
    { key: 'ok',    label: '정상' },
];
const filter = ref(props.result.summary?.error ? 'error' : 'all');

const summary = computed(() => props.result.summary ?? { total: 0, create: 0, update: 0, error: 0 });

// 행 번호 → 올린 값
const valuesByRow = computed(() => new Map((props.upload?.rows ?? []).map(r => [r.row, r.values ?? {}])));

const allRows = computed(() => (props.result.rows ?? []).map(r => ({
    ...prefixed(valuesByRow.value.get(r.row) ?? {}),
    _row:      r.row,
    _status:   r.status,
    _action:   r.action,
    _messages: (r.messages ?? []).join(' / '),
    _attributes: r.status === 'error' ? { className: { row: ['import-row-error'] } } : {},
})));

const gridRows = computed(() => filter.value === 'all' ? allRows.value : allRows.value.filter(r => r._status === filter.value));

function countOf(key) {
    return key === 'all' ? allRows.value.length : allRows.value.filter(r => r._status === key).length;
}

// 올린 값은 v_ 를 붙여 결과 칸( _row … )과 이름이 겹치지 않게
function prefixed(values) {
    return Object.fromEntries(Object.entries(values).map(([k, v]) => [`v_${k}`, v]));
}

const columns = computed(() => [
    { header: '행',   name: '_row',    width: 60, align: 'center' },
    {
        header: '결과', name: '_status', width: 70, align: 'center',
        formatter: ({ row }) => row._status === 'error' ? '오류' : (row._action === 'update' ? '수정' : '신규'),
    },
    { header: '오류 사유', name: '_messages', minWidth: 280, escapeHTML: true },
    ...(props.layout.columns ?? []).map(c => ({
        header:     c.header,
        name:       `v_${c.field}`,
        minWidth:   Math.max(80, (c.width ?? 12) * 8),
        escapeHTML: true,
    })),
]);
</script>

<style>
    /* 그리드 셀은 컴포넌트 밖에서 그려지므로 scoped 를 쓰지 않는다 */
    .import-row-error .tui-grid-cell { background-color: #fef2f2; color: #b91c1c; }
</style>

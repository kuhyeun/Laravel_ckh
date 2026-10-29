<!-- 엑셀 내보내기 버튼 ( 목록 화면 공통 )
     - layout : 양식 이름( 예 item.list ). 업체 → 공통 순서로 item.list.json · item.list.*.json 을 찾는다
                양식이 없으면 그리드 열 그대로, 하나면 바로, 여럿이면 고르는 목록
     - rows   : 내보낼 행. 배열이면 그대로, 함수면 눌렀을 때 호출 ( 서버 페이징 화면은 export=1 로 전체를 받아 오는 함수 )
     - columns: 화면 그리드 열 ( 기본 내보내기 · 표시값 )
     - grid   : DataGrid ref ( setting-key 를 쓴 그리드 ) — 주면 그 그리드의 열 설정( 순서 · 이름 · 숨김 → 엑셀 숨긴 열 )을 따른다
     - context: 양식 칸에 넣을 값 ( 출력자 등 )
     - label  : 버튼 글자 ( 기본 '엑셀' — 한 건 출력 양식이면 '발주서' 등 )
     - 양식은 개발자가 만들어 배포한다 — 사용자는 고르기만 한다 -->
<template>
    <div ref="root" class="relative inline-block">
        <button
            type="button"
            class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-50"
            :disabled="busy || disabled"
            @click="onClick"
        >
            <span>{{ busy ? '만드는 중…' : label }}</span>
            <span v-if="layouts.length > 1" class="text-[10px] text-gray-400">▾</span>
        </button>

        <!-- 양식이 여럿일 때 -->
        <div v-if="open" class="absolute right-0 mt-1 z-20 min-w-[200px] py-1 rounded border border-gray-200 bg-white shadow-lg">
            <button
                v-for="l in layouts"
                :key="l.id"
                type="button"
                class="w-full text-left px-3 py-1.5 text-xs text-gray-700 hover:bg-blue-50"
                @click="run(l)"
            >
                {{ l.name ?? l.id }}
                <span v-if="l.type === 'template'" class="ml-1 text-[10px] text-gray-400">양식</span>
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { loadLayouts, exportExcel } from '@core/Utils/excel';
import { showAlert } from '@core/Utils/message';

const props = defineProps({
    layout:   { type: String,          default: null },
    rows:     { type: [Array, Function], default: () => [] },
    columns:  { type: Array,           default: () => [] },
    grid:     { type: Object,          default: null },     // DataGrid ref — 열 설정을 따른다
    context:  { type: Object,          default: () => ({}) },
    filename: { type: String,          default: '목록' },
    disabled: { type: Boolean,         default: false },
    label:    { type: String,          default: '엑셀' },   // 버튼 글자 ( 예: 발주서 )
});

const page    = usePage();
const root    = ref(null);
const busy    = ref(false);
const open    = ref(false);
const layouts = ref([]);
let loaded    = false;

async function onClick() {
    if (open.value) { open.value = false; return; }
    busy.value = true;
    try {
        if (!loaded) {
            layouts.value = await loadLayouts(props.layout);
            loaded = true;
        }
    } catch (e) {
        layouts.value = [];   // 양식 조회 실패 → 그리드 열 그대로
    } finally {
        busy.value = false;
    }

    if (layouts.value.length > 1) {
        open.value = true;
        return;
    }
    run(layouts.value[0] ?? null);
}

async function run(layout) {
    open.value = false;
    busy.value = true;
    try {
        const rows = typeof props.rows === 'function' ? await props.rows() : props.rows;
        if (!rows?.length) {
            await showAlert('엑셀', '내보낼 행이 없습니다.', 'info');
            return;
        }
        await exportExcel({
            layout,
            rows,
            columns: props.grid?.exportColumns ?? props.columns,
            context: { userName: page.props.auth?.user?.user_name ?? '', ...props.context },
            filename: props.filename,
        });
    } catch (e) {
        // 서버 상한 초과( 422 ) 등은 서버 문구를 그대로
        const msg = e.response?.data?.errors?.export?.[0] ?? e.response?.data?.message;
        await showAlert('엑셀', msg || '엑셀 파일을 만들지 못했습니다.', 'error');
    } finally {
        busy.value = false;
    }
}

// 바깥을 누르면 목록 닫기
const onDocClick = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

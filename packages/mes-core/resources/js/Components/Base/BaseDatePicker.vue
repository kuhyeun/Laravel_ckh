<!-- 날짜 · 일시 선택 ( 2026-10-29 채택 — 모든 날짜 · 일시 입력 ) — @vuepic/vue-datepicker 11.0.3 을 감싼다
     - 라이브러리는 이 파일 안에서만 쓴다 ( 바꾸거나 지울 때 이 파일만 고치면 된다 — vite.config.js alias 2줄 · package.json )
     - 값은 브라우저 기본 입력과 같은 문자열 : date = "2026-10-29" / datetime = "2026-10-29T15:00" ( 서버 · 화면 로직을 바꾸지 않고 갈아 끼우게 )
     - mode : date | datetime · size : sm | md ( BaseInput 과 같은 높이 ) · 일요일 빨강 · 토요일 파랑 · 테마 색은 시스템 파랑 -->
<template>
    <div class="base-dp" :class="[`base-dp--${size}`, mode === 'datetime' ? 'base-dp--dt' : 'base-dp--d']">
        <VueDatePicker
            :model-value="modelValue || null"
            :model-type="mode === 'datetime' ? `yyyy-MM-dd'T'HH:mm` : 'yyyy-MM-dd'"
            :format="mode === 'datetime' ? 'yyyy-MM-dd HH:mm' : 'yyyy-MM-dd'"
            :enable-time-picker="mode === 'datetime'"
            :time-picker-inline="true"
            :is-24="true"
            :auto-apply="mode === 'date'"
            :clearable="clearable"
            :disabled="disabled"
            :placeholder="placeholder"
            :week-start="0"
            :day-names="DAY_NAMES"
            :month-change-on-scroll="false"
            :teleport="true"
            locale="ko"
            select-text="선택"
            cancel-text="취소"
            now-button-label="지금"
            @update:model-value="v => emit('update:modelValue', v ?? '')"
            @internal-model-change="d => { inner = d instanceof Date ? d : null; }"
            @closed="inner = null"
        >
            <!-- 시 · 분 목록의 지금 값 파랑 ( 11-25 ) — 라이브러리는 시간 목록에 선택 표시를 끄고 있다( active: false 고정 ) → 칸 안에 표시를 넣고 CSS :has 로 칸을 칠한다 -->
            <template v-if="mode === 'datetime'" #hours-overlay-value="{ text, value }">
                <span :class="{ 'dp-tp-on': value === curTime.h }">{{ text }}</span>
            </template>
            <template v-if="mode === 'datetime'" #minutes-overlay-value="{ text, value }">
                <span :class="{ 'dp-tp-on': value === curTime.m }">{{ text }}</span>
            </template>
        </VueDatePicker>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps({
    modelValue:  { type: [String, null], default: '' },   // 빈 값 '' · null 모두 받음 ( 지우면 '' )
    mode:        { type: String, default: 'date' },   // date | datetime
    size:        { type: String, default: 'md' },     // sm | md
    disabled:    { type: Boolean, default: false },
    clearable:   { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const DAY_NAMES = ['일', '월', '화', '수', '목', '금', '토'];

// 메뉴 안에서 고르는 중인 값( [선택] 전 ) — 없으면 입력값
const inner = ref(null);
const curTime = computed(() => {
    if (inner.value) return { h: inner.value.getHours(), m: inner.value.getMinutes() };
    const t = /T(\d{2}):(\d{2})/.exec(props.modelValue || '');
    return t ? { h: +t[1], m: +t[2] } : { h: null, m: null };
});
</script>

<style>
/* 테마 — 달력 메뉴는 body 로 옮겨 그려지므로( teleport ) scoped 가 아니다. 이 클래스들은 이 라이브러리 것뿐이라 다른 화면에 영향 없음 */
.dp__theme_light {
    --dp-font-family: var(--app-font);
    --dp-font-size: 13px;
    --dp-preview-font-size: 12px;
    --dp-border-radius: 6px;
    --dp-cell-border-radius: 6px;
    --dp-cell-size: 32px;
    --dp-cell-padding: 4px;
    --dp-menu-padding: 8px 10px;
    --dp-menu-min-width: 268px;
    --dp-input-padding: 0 28px 0 34px;
    --dp-input-icon-padding: 34px;
    --dp-action-button-height: 28px;
    --dp-action-buttons-padding: 0 12px;
    --dp-time-font-size: 1.6rem;

    --dp-primary-color: theme('colors.blue.600');
    --dp-primary-disabled-color: theme('colors.blue.300');
    --dp-text-color: theme('colors.gray.700');
    --dp-secondary-color: theme('colors.gray.300');
    --dp-hover-color: theme('colors.blue.50');
    --dp-hover-text-color: theme('colors.blue.700');
    --dp-hover-icon-color: theme('colors.blue.600');
    --dp-icon-color: theme('colors.gray.400');
    --dp-border-color: theme('colors.gray.300');
    --dp-border-color-hover: theme('colors.gray.400');
    --dp-border-color-focus: theme('colors.gray.500');
    --dp-menu-border-color: theme('colors.gray.200');
    --dp-disabled-color: theme('colors.gray.100');
    --dp-disabled-color-text: theme('colors.gray.400');
    --dp-highlight-color: theme('colors.blue.600 / 10%');
}

/* 입력칸 : BaseInput 과 같은 높이 · 테두리 · 포커스 */
.base-dp .dp__input { height: 32px; color: theme('colors.gray.700'); }
.base-dp--sm .dp__input { height: 28px; font-size: 13px; }
.base-dp .dp__input_focus { box-shadow: 0 0 0 2px theme('colors.gray.500 / 12%'); }
.base-dp .dp__input_icon { color: theme('colors.gray.400'); }
.base-dp .dp__input:hover + .dp__input_icon,
.base-dp .dp__input_focus ~ .dp__input_icon { color: theme('colors.blue.600'); }
.base-dp--d  { min-width: 8.75rem; }
.base-dp--dt { min-width: 13rem; }

/* 메뉴 : 그림자 · 머리 · 오늘 · 선택 · 주말 색 */
.dp__menu { box-shadow: 0 10px 28px rgba(15, 23, 42, 0.14); border-radius: 10px; }
.dp__month_year_select { font-weight: 600; }
.dp__calendar_header { font-weight: 500; color: theme('colors.gray.500'); }
.dp__calendar_header_item:first-child { color: theme('colors.red.600'); }
.dp__calendar_header_item:last-child { color: theme('colors.blue.600'); }
.dp__calendar_row > .dp__calendar_item:first-child .dp__cell_inner:not(.dp__active_date):not(.dp__cell_offset) { color: theme('colors.red.600'); }
.dp__calendar_row > .dp__calendar_item:last-child .dp__cell_inner:not(.dp__active_date):not(.dp__cell_offset) { color: theme('colors.blue.600'); }
.dp__today { border-color: theme('colors.blue.300'); font-weight: 600; }
.dp__active_date { font-weight: 600; }
.dp__action_row { border-top: 1px solid theme('colors.gray.100'); }
.dp__action_select { padding: 0 14px !important; }

/* 시간 : 달력 아래에 늘 보이게 ( time-picker-inline · 11-25 ) — 시계 아이콘을 눌러야 나오던 것을 한눈에
   기본 ▲▼ 는 회색 막대라 버튼으로 안 보인다 → 막대를 숨기고 삼각형 · 왼쪽에 「시간」 라벨 · 숫자를 눌러 목록에서 고르기도 그대로 */
/*   라벨은 absolute 로 두지 않는다 — 시간 줄에 position 을 주면 시 · 분 목록( overlay )이 메뉴 전체가 아니라 이 줄 위에만 뜬다
     → 앞( ::before ) 「시간」 · 뒤( ::after ) 같은 폭 빈 칸으로 시 : 분 을 가운데에 */
.dp__time_picker_inline_container {
    align-items: center;
    margin-top: 4px;
    padding-top: 6px;
    border-top: 1px solid theme('colors.gray.100');
}
.dp__time_picker_inline_container::before,
.dp__time_picker_inline_container::after {
    content: '시간';
    flex: 1;
    padding-left: 8px;
    font-size: 12px;
    font-weight: 500;
    color: theme('colors.gray.500');
}
.dp__time_picker_inline_container::after { visibility: hidden; }
.dp__inc_dec_button_inline {
    height: 18px;
    justify-content: center;
    border-radius: 4px;
}
.dp__inc_dec_button_inline:hover { background: theme('colors.blue.50'); }
.dp__tp_inline_btn_bar { display: none; }
.dp__tp_inline_btn_top::before,
.dp__tp_inline_btn_bottom::before {
    content: '';
    border: 5px solid transparent;
}
.dp__tp_inline_btn_top::before    { border-top-width: 0;    border-bottom-color: theme('colors.gray.400'); }
.dp__tp_inline_btn_bottom::before { border-bottom-width: 0; border-top-color: theme('colors.gray.400'); }
.dp__tp_inline_btn_top:hover::before    { border-bottom-color: theme('colors.blue.600'); }
.dp__tp_inline_btn_bottom:hover::before { border-top-color: theme('colors.blue.600'); }
.dp__time_display_inline {
    min-width: 40px;
    padding: 3px 8px;
    font-size: 16px;
    font-weight: 600;
    border: 1px solid theme('colors.gray.200');
    border-radius: 6px;
}

/* 시 · 분 숫자를 눌러 여는 목록 ( 11-25 ) — 기본은 한 줄 3칸 · 낮은 높이라 스크롤하며 찾아야 했다
   → 6칸 격자로 한 번에 다 보이게 : 시 24개 = 4줄 · 분( 5분 단위 ) 12개 = 2줄 · 칸도 크게 */
.dp__overlay[aria-label="hours overlay"] .dp__overlay_container,
.dp__overlay[aria-label="minutes overlay"] .dp__overlay_container {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 4px;
    align-content: center;
    height: calc(100% - 40px) !important;   /* 아래 40px : 달력으로 돌아가는 버튼 */
    overflow: visible;
    padding: 8px 6px 0;
}
.dp__overlay[aria-label="hours overlay"] .dp__overlay_row,
.dp__overlay[aria-label="minutes overlay"] .dp__overlay_row { display: contents; }
.dp__overlay[aria-label="hours overlay"] .dp__selection_grid_header,
.dp__overlay[aria-label="minutes overlay"] .dp__selection_grid_header {
    grid-column: 1 / -1;
    padding: 0 4px 6px;
    font-size: 12px;
    font-weight: 600;
    color: theme('colors.gray.500');
}
.dp__overlay[aria-label="hours overlay"] .dp__selection_grid_header::before { content: '시 선택'; }
.dp__overlay[aria-label="minutes overlay"] .dp__selection_grid_header::before { content: '분 선택 ( 5분 단위 · 1분씩은 ▲▼ )'; }
.dp__overlay[aria-label="hours overlay"] .dp__overlay_col,
.dp__overlay[aria-label="minutes overlay"] .dp__overlay_col {
    width: auto;
    padding: 0;
}
.dp__overlay[aria-label="hours overlay"] .dp__overlay_cell_pad,
.dp__overlay[aria-label="minutes overlay"] .dp__overlay_cell_pad {
    padding: 9px 0;
    font-size: 14px;
}
.dp__overlay_cell:has(.dp-tp-on) {
    background: var(--dp-primary-color);
    color: #fff;
    font-weight: 600;
}
</style>

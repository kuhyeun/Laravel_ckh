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
        />
    </div>
</template>

<script setup>
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

defineProps({
    modelValue:  { type: [String, null], default: '' },   // 빈 값 '' · null 모두 받음 ( 지우면 '' )
    mode:        { type: String, default: 'date' },   // date | datetime
    size:        { type: String, default: 'md' },     // sm | md
    disabled:    { type: Boolean, default: false },
    clearable:   { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const DAY_NAMES = ['일', '월', '화', '수', '목', '금', '토'];
</script>

<style>
/* 테마 — 달력 메뉴는 body 로 옮겨 그려지므로( teleport ) scoped 가 아니다. 이 클래스들은 이 라이브러리 것뿐이라 다른 화면에 영향 없음 */
.dp__theme_light {
    --dp-font-family: inherit;
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

    --dp-primary-color: #2563eb;
    --dp-primary-disabled-color: #93c5fd;
    --dp-text-color: #374151;
    --dp-secondary-color: #c0c4cc;
    --dp-hover-color: #eff6ff;
    --dp-hover-text-color: #1d4ed8;
    --dp-hover-icon-color: #2563eb;
    --dp-icon-color: #9ca3af;
    --dp-border-color: #d1d5db;
    --dp-border-color-hover: #9ca3af;
    --dp-border-color-focus: #6b7280;
    --dp-menu-border-color: #e5e7eb;
    --dp-disabled-color: #f3f4f6;
    --dp-disabled-color-text: #9ca3af;
    --dp-highlight-color: rgb(37 99 235 / 10%);
}

/* 입력칸 : BaseInput 과 같은 높이 · 테두리 · 포커스 */
.base-dp .dp__input { height: 36px; color: #374151; }
.base-dp--sm .dp__input { height: 30px; font-size: 13px; }
.base-dp .dp__input_focus { box-shadow: 0 0 0 2px rgba(107, 114, 128, 0.12); }
.base-dp .dp__input_icon { color: #9ca3af; }
.base-dp .dp__input:hover + .dp__input_icon,
.base-dp .dp__input_focus ~ .dp__input_icon { color: #2563eb; }
.base-dp--d  { min-width: 8.75rem; }
.base-dp--dt { min-width: 13rem; }

/* 메뉴 : 그림자 · 머리 · 오늘 · 선택 · 주말 색 */
.dp__menu { box-shadow: 0 10px 28px rgba(15, 23, 42, 0.14); border-radius: 10px; }
.dp__month_year_select { font-weight: 600; }
.dp__calendar_header { font-weight: 500; color: #6b7280; }
.dp__calendar_header_item:first-child { color: #dc2626; }
.dp__calendar_header_item:last-child { color: #2563eb; }
.dp__calendar_row > .dp__calendar_item:first-child .dp__cell_inner:not(.dp__active_date):not(.dp__cell_offset) { color: #dc2626; }
.dp__calendar_row > .dp__calendar_item:last-child .dp__cell_inner:not(.dp__active_date):not(.dp__cell_offset) { color: #2563eb; }
.dp__today { border-color: #93c5fd; font-weight: 600; }
.dp__active_date { font-weight: 600; }
.dp__action_row { border-top: 1px solid #f1f5f9; }
.dp__action_select { padding: 0 14px !important; }
</style>

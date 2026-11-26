<!-- 모니터링 판 하나 ( 11-26 ) — 흰( 어두우면 진한 ) 판 + 제목 줄 · 본문은 남는 높이를 다 쓴다
     - title : 제목 · #sub : 제목 옆 작은 글 · #right : 제목 줄 오른쪽 ( 범례 · 쪽 표시 등 ) · 기본 슬롯 = 본문
     - slideOn · slideCycle : 장 넘김 진행 막대 ( 제목 줄 아래 선 자리 · 얇게 · slideSec 동안 차오르고 장이 바뀌면 처음부터 ) -->
<template>
    <div class="mp">
        <div class="mp-title">
            {{ title }}
            <small v-if="$slots.sub"><slot name="sub" /></small>
            <span v-if="$slots.right" class="mp-right"><slot name="right" /></span>
            <span v-if="slideOn" class="mp-slide" :title="`${slideSec}초마다 다음 장`">
                <i :key="slideCycle" :style="{ animationDuration: `${slideSec}s` }"></i>
            </span>
        </div>
        <div class="mp-body"><slot /></div>
    </div>
</template>

<script setup>
import { slideSec } from '@core/Components/Monitor/monitorSettings';

defineProps({
    title:      { type: String,  default: '' },
    slideOn:    { type: Boolean, default: false },
    slideCycle: { type: Number,  default: 0 },
});
</script>

<style scoped>
.mp { min-width: 0; min-height: 0; display: flex; flex-direction: column; background: var(--m-surface); border: 1px solid var(--m-border); border-radius: 1.4vh; padding: 1.4vh 1.6vh; box-shadow: var(--m-shadow); transition: background 0.3s, border-color 0.3s; }
.mp-title { position: relative; flex: none; display: flex; align-items: center; gap: 0.8vh; padding-bottom: 1vh; margin-bottom: 1.2vh; border-bottom: 1px solid var(--m-border-soft); font-size: 2vh; font-weight: 700; color: var(--m-text); }
.mp-title::before { content: ''; width: 0.5vh; height: 2vh; border-radius: 0.3vh; background: var(--m-bar); }
.mp-title small { font-size: 1.5vh; font-weight: 500; color: var(--m-mute); }
.mp-right { margin-left: auto; display: flex; align-items: center; gap: 0.6vh; font-size: 1.5vh; font-weight: 600; color: var(--m-sub); }
/* 장 넘김 진행 막대 — 제목 줄 아래 선 위에 얇게 */
.mp-slide { position: absolute; left: 0; right: 0; bottom: -2px; height: 3px; border-radius: 2px; background: var(--m-border-soft); overflow: hidden; }
.mp-slide i { display: block; height: 100%; width: 0; border-radius: 2px; background: var(--m-bar); animation-name: mp-fill; animation-timing-function: linear; animation-fill-mode: forwards; }
@keyframes mp-fill { from { width: 0; } to { width: 100%; } }
.mp-body { flex: 1; min-height: 0; position: relative; }
</style>

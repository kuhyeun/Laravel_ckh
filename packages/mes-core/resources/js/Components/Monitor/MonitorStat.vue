<!-- 모니터링 큰 숫자 칸 ( 11-26 ) — 이름 · 값 · 단위 · 아래 작은 글 · tone( 색 줄 ) : accent · on · off · now · idle · maint -->
<template>
    <div class="ms" :class="`t-${tone}`">
        <div class="ms-label">{{ label }}</div>
        <div class="ms-value">{{ value }}<small v-if="unit">{{ unit }}</small></div>
        <div v-if="sub || $slots.sub" class="ms-sub"><slot name="sub">{{ sub }}</slot></div>
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, default: '' },
    value: { type: [String, Number], default: '' },
    unit:  { type: String, default: '' },
    sub:   { type: String, default: '' },
    tone:  { type: String, default: 'accent' },
});
</script>

<style scoped>
.ms { position: relative; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.4vh; padding: 1.4vh 2vh 1.4vh 2.4vh; background: var(--m-surface); border: 1px solid var(--m-border); border-radius: 1.4vh; box-shadow: var(--m-shadow); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
.ms::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 0.6vh; background: var(--tone); }
.ms-label { font-size: 1.6vh; font-weight: 700; color: var(--m-sub); }
.ms-value { font-size: 4.2vh; font-weight: 800; line-height: 1.05; color: var(--tone); font-variant-numeric: tabular-nums; white-space: nowrap; }
.ms-value small { font-size: 1.8vh; font-weight: 600; margin-left: 0.5vh; color: var(--m-mute); }
.ms-sub { font-size: 1.4vh; color: var(--m-mute); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-accent { --tone: var(--m-accent); }
.t-on { --tone: var(--m-on-text); }
.t-off { --tone: var(--m-off-text); }
.t-now { --tone: var(--m-now); }
.t-idle { --tone: var(--m-idle); }
.t-maint { --tone: var(--m-maint); }
.t-text { --tone: var(--m-text); }
</style>

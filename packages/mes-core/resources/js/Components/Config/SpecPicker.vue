<!-- 규격 고르기 — 코드관리 규격( SPEC ) 목록에서 찾아 고르고, 없으면 새 규격 ( docs/품목-규격구분-설계.md §5 )
     - 입력 칸 그대로 쓸 수 있다( 목록에 없는 규격도 — 품목을 저장하면 규격 코드에 자동으로 더해진다 )
     - 찾기 : 띄어 쓴 낱말이 모두 들어 있는 규격 ( 공백 · 대소문자 · x/× 무시 )
     - [+ 새 규격 추가] ( canCreate — 품목관리 쓰기 ) : 바로 규격 코드에 더하고 고른다 ( 같은 규격이 있으면 그것 )
     - options ( 규격 문자열 배열 ) : 주면 코드관리 규격 대신 이 목록만 — 수주 [자재] 창( 그 품번으로 등록된 자재의 규격만 )
         이때 [+ 새 규격 추가]는 없고, 목록에 없는 규격은 newHint 로 안내 ( 정하면 여는 쪽이 새 자재로 등록 )
         지금 값이 목록에 있으면( 바꾸기 전 ) 목록을 다 보인다 · open : 목록을 열 때 ( 여는 쪽이 이때 불러온다 )
     - commit : 규격을 정했을 때( 목록에서 고름 · Enter · 칸을 떠남 · 새 규격 추가 ) — 규격으로 품목을 바꾸는 곳( 수주 [자재] 창 )이 쓴다
     - 키보드 : ↓ / ↑ 로 목록 이동( 마지막은 [+ 새 규격 추가] ) · Enter 로 고름( 가리킨 줄이 없으면 결과가 하나일 때 그것 · 아니면 입력한 그대로 ) · Esc 닫기
     - 목록은 body 로 띄운다 ( 겹친 모달보다 위 — z 5000 · ItemPicker 와 같다 ) -->
<template>
    <div ref="root" class="relative">
        <input ref="input" :value="modelValue ?? ''" class="base-input w-full" :class="sizeClass" :placeholder="placeholder" :disabled="disabled" maxlength="100"
            @focus="openList" @input="onInput" @blur="onBlur" @keydown.esc.stop="close" @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
        <p v-if="hint" class="text-[11px] mt-0.5" :class="hint.cls">{{ hint.text }}</p>

        <Teleport to="body">
            <div v-if="open && (results.length || showAdd)" ref="pop" class="fixed z-[5000] bg-white border border-gray-300 rounded shadow-lg text-sm" :style="popStyle">
                <div ref="list" class="max-h-60 overflow-y-auto">
                    <button v-for="(c, i) in results" :key="c.key" type="button" tabindex="-1" data-opt class="w-full text-left px-2.5 py-1.5"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', specKey(c.name) === specKey(modelValue) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @mousedown.prevent="pick(c.name)">
                        {{ c.name }}
                    </button>
                </div>
                <div v-if="showAdd" class="border-t p-1.5">
                    <button type="button" tabindex="-1" class="w-full text-left px-1.5 py-1 text-blue-700 rounded" :class="active === results.length ? 'bg-blue-100' : 'hover:bg-blue-50'"
                        :disabled="adding" @mousemove="active = results.length" @mousedown.prevent="addSpec">
                        + 새 규격 추가 ( {{ text }} )
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
import { specKey } from '@core/Utils/format';
import { useCommonCode, clearCommonCodeCache } from '@core/Composables/useCommonCode';

const props = defineProps({
    modelValue:  { type: String,  default: '' },
    disabled:    { type: Boolean, default: false },
    canCreate:   { type: Boolean, default: false },   // [+ 새 규격 추가] ( 품목관리 쓰기 권한 )
    placeholder: { type: String,  default: '예: Φ10×1000 · 100x10 ( 없으면 비움 )' },
    size:        { type: String,  default: 'md' },
    options:     { type: Array,   default: null },     // [ '100x10', … ] — 주면 이 목록만 ( 코드관리 규격 대신 )
    newHint:     { type: String,  default: '' },       // options 모드 : 목록에 없는 규격일 때 안내
});
const emit = defineEmits(['update:modelValue', 'commit', 'open']);

const LIMIT = 50;
const { codes } = useCommonCode(['SPEC']);   // codes.SPEC = [{ key, name, value, is_use }] ( 사용 중만 )
const added = ref([]);   // 이 화면에서 새로 더한 규격 ( 캐시를 다시 부르지 않고 바로 보이게 )
const specs = computed(() => {
    if (props.options) return props.options.filter(Boolean).map(name => ({ key: specKey(name), name }));
    const list = codes.SPEC ?? [];
    const keys = new Set(list.map(c => specKey(c.name)));
    return [...list, ...added.value.filter(c => !keys.has(specKey(c.name)))];
});

const root = ref(null);
const pop = ref(null);
const input = ref(null);
const list = ref(null);
const active = ref(-1);   // 키보드로 가리킨 줄 ( -1 = 없음 · results.length = [+ 새 규격 추가] )
const open = ref(false);
const adding = ref(false);
const popStyle = ref({});
// size : core.css 의 .base-input.sm / .lg 에 맡긴다 ( BaseInput 과 같은 높이 — sm 30px · md 36px · lg 42px )
const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));

const text = computed(() => String(props.modelValue ?? '').trim());
const exact = computed(() => text.value !== '' && specs.value.some(c => specKey(c.name) === specKey(text.value)));
const results = computed(() => {
    if (props.options && (text.value === '' || exact.value)) return specs.value.slice(0, LIMIT);   // 바꾸기 전 : 다 보인다
    const words = text.value.split(/\s+/).filter(Boolean).map(specKey);
    return specs.value.filter(c => words.every(w => specKey(c.name).includes(w))).slice(0, LIMIT);
});
const showAdd = computed(() => !props.options && props.canCreate && text.value !== '' && !exact.value);
// 칸 아래 안내 : 목록에 있는 규격 / 새 규격( 저장하면 규격 코드에 더해짐 )
const hint = computed(() => {
    if (text.value === '' || props.disabled) return null;
    if (props.options) return exact.value || !props.newHint ? null : { text: props.newHint, cls: 'text-amber-600' };
    return exact.value ? null : { text: '새 규격입니다. 저장하면 규격 코드에 추가됩니다.', cls: 'text-gray-400' };
});

watch(text, () => { active.value = -1; });

// ↓ / ↑ : 닫혀 있으면 열고, 열려 있으면 가리킨 줄을 옮긴다
function move(d) {
    if (!open.value) { openList(); return; }
    const n = results.value.length + (showAdd.value ? 1 : 0);
    if (!n) return;
    active.value = Math.max(0, Math.min(n - 1, active.value + d));
    nextTick(() => list.value?.querySelectorAll('[data-opt]')[active.value]?.scrollIntoView({ block: 'nearest' }));
}
function pickActive() {
    if (open.value && active.value >= 0 && active.value < results.value.length) pick(results.value[active.value].name);
    else if (open.value && active.value === results.value.length && showAdd.value) addSpec();
    else pickFirst();
}

function place() {
    const r = root.value.getBoundingClientRect();
    const width = Math.max(r.width, 240);
    const below = window.innerHeight - r.bottom > 280;
    popStyle.value = {
        left: `${Math.min(r.left, window.innerWidth - width - 8)}px`, width: `${width}px`,
        ...(below ? { top: `${r.top + (input.value?.offsetHeight ?? r.height) + 2}px` } : { bottom: `${window.innerHeight - r.top + 2}px` }),
    };
}
function openList() {
    if (props.disabled) return;
    place();
    active.value = -1;
    open.value = true;
    emit('open');
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
}
function close() {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close);
}
function onOutside(e) {
    if (root.value?.contains(e.target) || pop.value?.contains(e.target)) return;
    close();
}
function onScroll(e) {
    if (pop.value?.contains(e.target)) return;
    close();
}
onBeforeUnmount(close);

function onInput(e) {
    emit('update:modelValue', e.target.value);
    if (!open.value) openList();
}
function pick(name) {
    emit('update:modelValue', name);
    emit('commit', name);
    close();
}
function pickFirst() {
    if (results.value.length === 1) pick(results.value[0].name);
    else { emit('commit', text.value); close(); }
}
// 칸을 떠나면 입력한 그대로 확정 ( 목록을 누르는 중이면 그쪽이 먼저 — mousedown.prevent )
function onBlur() {
    if (!adding.value) emit('commit', text.value);
}

async function addSpec() {
    adding.value = true;
    try {
        const { data } = await window.axios.post(route('config.item.quickSpec'), { spec: text.value });
        added.value.push({ key: data.code.key, name: data.code.name });
        clearCommonCodeCache(['SPEC']);
        pick(data.code.name);
    } catch (e) {
        // 권한이 없거나 실패해도 입력한 값은 그대로 — 품목을 저장하면 서버가 규격 코드에 더한다
        close();
    } finally {
        adding.value = false;
    }
}
</script>

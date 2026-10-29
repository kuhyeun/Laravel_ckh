<!-- 그리드 열 설정 창 ( DataGrid setting-key — 오른쪽 클릭 "열 설정…" · GridSettingButton )
     - 열마다 표시 · 이름 · 순서 ( ↑↓ 또는 끌어 옮기기 ) — 사용자 자신의 설정 ( 사용자 × 화면 × 그리드 )
     - 숨긴 열도 엑셀에는 나간다 ( 엑셀에서 숨김 처리 — 필요하면 펼쳐 본다 )
     - 꼭 있어야 하는 열( lockHide )은 숨길 수 없다 / [기본값으로] = 내 설정을 지우고 화면 기본 구성으로 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p class="text-xs text-gray-500 mb-2 shrink-0">체크를 끄면 화면에서 숨깁니다 ( 엑셀에는 숨긴 열로 나갑니다 ). 이름을 비우면 원래 이름으로 돌아갑니다.</p>
        <div class="flex-1 min-h-0 overflow-y-auto border border-gray-200 rounded">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 sticky top-0">
                    <tr>
                        <th class="w-10 px-2 py-1.5">표시</th>
                        <th class="px-2 py-1.5 text-left">이름</th>
                        <th class="px-2 py-1.5 text-left w-32">원래 이름</th>
                        <th class="w-20 px-2 py-1.5">순서</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(c, i) in list" :key="c.name" class="border-t border-gray-100" :class="[dragIdx === i ? 'opacity-40' : '', overIdx === i && dragIdx !== i ? 'bg-blue-50' : '']"
                        draggable="true" @dragstart="dragIdx = i" @dragover.prevent="overIdx = i" @dragleave="overIdx = null" @drop="drop(i)" @dragend="dragIdx = overIdx = null">
                        <td class="px-2 py-1 text-center">
                            <input type="checkbox" class="row-check accent-blue-600" :checked="!c.hidden" :disabled="c.lockHide" :title="c.lockHide ? '꼭 있어야 하는 열입니다' : ''" @change="c.hidden = !$event.target.checked" />
                        </td>
                        <td class="px-2 py-1"><BaseInput v-model="c.header" size="sm" class="w-full" :placeholder="c.origHeader" maxlength="50" /></td>
                        <td class="px-2 py-1 text-xs text-gray-400 truncate">{{ c.origHeader }}</td>
                        <td class="px-2 py-1 text-center whitespace-nowrap">
                            <span class="cursor-grab text-gray-300 mr-1" title="끌어서 옮기기">⠿</span>
                            <button type="button" class="px-1 text-gray-500 hover:text-blue-700 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)">↑</button>
                            <button type="button" class="px-1 text-gray-500 hover:text-blue-700 disabled:opacity-30" :disabled="i === list.length - 1" @click="move(i, 1)">↓</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="errorMsg" class="mt-2 text-xs text-red-500 shrink-0">{{ errorMsg }}</p>

        <div class="flex items-center gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton size="sm" variant="secondary" :disabled="busy" @click="reset">기본값으로</BaseButton>
            <span class="text-xs text-gray-400">보이는 열 {{ list.filter(c => !c.hidden).length }} / {{ list.length }}</span>
            <div class="flex-1"></div>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">취소</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="busy || !list.some(c => !c.hidden)" @click="apply">{{ busy ? '저장 중…' : '저장' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    columns: { type: Array,    required: true },   // 설정 대상 열 ( 현재 순서 ) — { name, header, _origHeader, _userHidden, lockHide, _savedWidth }
    onApply: { type: Function, required: true },   // ( [{ name, header, hidden }] ) → Promise
    onReset: { type: Function, required: true },   // () → Promise
});
const emit = defineEmits(['close']);

const list = reactive(props.columns.map(c => ({
    name:       c.name,
    origHeader: c._origHeader ?? c.header,
    header:     c.header !== (c._origHeader ?? c.header) ? c.header : '',
    hidden:     !!c._userHidden,
    lockHide:   !!c.lockHide,
})));
const busy     = ref(false);
const errorMsg = ref('');
const dragIdx  = ref(null);
const overIdx  = ref(null);

function move(i, d) {
    const [c] = list.splice(i, 1);
    list.splice(i + d, 0, c);
}
function drop(i) {
    if (dragIdx.value === null || dragIdx.value === i) return;
    const [c] = list.splice(dragIdx.value, 1);
    list.splice(i, 0, c);
    dragIdx.value = overIdx.value = null;
}

async function run(fn) {
    busy.value = true;
    errorMsg.value = '';
    try {
        await fn();
        emit('close');
    } catch (e) {
        errorMsg.value = '저장하지 못했습니다.';
    } finally {
        busy.value = false;
    }
}
const apply = () => run(() => props.onApply(list.map(c => ({ name: c.name, header: c.header.trim() || null, hidden: c.hidden && !c.lockHide }))));
const reset = () => run(() => props.onReset());
</script>

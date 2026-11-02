import { defineStore } from 'pinia';
import { ref } from 'vue';

// 화면 알림 Toast ( 오른쪽 위 · 쌓임 ) — 화면은 useToast().showFlash 로 부른다 ( style-guide §7 )
//   SweetAlert toast 를 쓰지 않는 이유 : SweetAlert 는 창이 하나라 확인창이 떠 있을 때 부르면 확인창이 닫힌다
export const useToastStore = defineStore('toast', () => {
    const MAX = 5;   // 넘치면 오래된 것부터 뺀다
    const DURATION = { success: 3000, warning: 6000, error: 6000 };

    const items = ref([]);
    let seq = 0;

    // type : success | warning | error
    function push(text, type = 'success', ms = null) {
        const id = ++seq;
        items.value.push({ id, text, type, ms: ms ?? DURATION[type] ?? 3000 });
        if (items.value.length > MAX) items.value.shift();
        return id;
    }

    function remove(id) {
        items.value = items.value.filter(t => t.id !== id);
    }

    return { items, push, remove };
});

import { ref, watch, onBeforeUnmount } from 'vue';

// 조회 중 표시( 버튼 비활성 등 )를 일정 시간이 지난 뒤에만 켠다
//   빠른 조회에서 버튼이 흐려졌다 돌아오며 깜빡이는 것을 막는다. 끝나면 바로 끈다
//   사용 : const showBusy = useDelayedBusy(() => loading.value)  →  <BaseButton :disabled="showBusy">
export function useDelayedBusy(source, delayMs = 300) {
    const busy = ref(false);
    let timer = null;

    watch(source, (on) => {
        clearTimeout(timer);
        if (on) {
            timer = setTimeout(() => { busy.value = true; }, delayMs);
        } else {
            busy.value = false;
        }
    });

    onBeforeUnmount(() => clearTimeout(timer));

    return busy;
}

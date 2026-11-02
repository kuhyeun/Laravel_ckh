// 화면 알림 — Toast ( 오른쪽 위 ) — packages/mes-core/docs/style-guide.md §7
//   const { showFlash } = useToast();
//   showFlash('저장했습니다.') / showFlash(failMsg(e), true) / showFlash('출고했습니다.', false, ['재고가 모자랍니다.'])
//   경고( warnings · 문구 안의 ⚠ )가 있으면 노랑. 모달 안 문구는 그 자리에 ( useFlash )
import { useToastStore } from '@core/Stores/toastStore';
import { failMsg } from '@core/Composables/useFlash';

export { failMsg };

export function useToast() {
    const store = useToastStore();

    function showFlash(text, error = false, warnings = []) {
        if (!text) return;
        const body = warnings.length ? `${text}\n⚠ ${warnings.join('\n⚠ ')}` : text;
        store.push(body, error ? 'error' : body.includes('⚠') ? 'warning' : 'success');
    }

    return { showFlash, failMsg };
}

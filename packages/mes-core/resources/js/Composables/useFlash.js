// 제목 줄 알림 문구 — packages/mes-core/docs/style-guide.md §7
//   const { flash, showFlash, failMsg } = useFlash();
//   <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
//   showFlash('저장했습니다.') / showFlash(failMsg(e), true) / showFlash('출고했습니다.', false, ['재고가 모자랍니다.'])
import { reactive, onUnmounted } from 'vue';

export function useFlash(ms = 5000) {
    const flash = reactive({ text: '', error: false });
    let timer = null;

    // warnings 가 있으면 성공 문구 뒤에 ⚠ 줄로
    function showFlash(text, error = false, warnings = []) {
        flash.text  = warnings.length ? `${text}\n⚠ ${warnings.join('\n⚠ ')}` : text;
        flash.error = error;
        clearTimeout(timer);
        timer = setTimeout(() => { flash.text = ''; }, ms);
    }

    onUnmounted(() => clearTimeout(timer));

    return { flash, showFlash, failMsg };
}

// axios 오류 → 문구 : 422 서버 문구 그대로 · 403 권한 · 그 밖
export function failMsg(e) {
    const s = e?.response?.status;
    if (s === 422) return Object.values(e.response.data.errors ?? {}).flat().join('\n') || '입력을 확인하세요.';
    if (s === 403) return '권한이 없습니다.';
    return '처리하지 못했습니다.';
}

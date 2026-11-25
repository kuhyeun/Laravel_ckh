// 저장 실패 처리 ( 공통 · 11-23 ) — 창( 모달 ) · 패널의 입력 칸 오류
//   const { errors, errorMsg, clear, handle } = useFormErrors(['qty', 'inspected_at', 'defects']);
//   try { … } catch (e) { handle(e, '저장하지 못했습니다.'); }
//
//   422 : errors = 서버 칸별 오류( 칸 아래 FormField 가 보인다 ) · shown 에 없는 칸의 문구는 errorMsg 로 모아 위에 ( 줄바꿈 )
//         shown 은 칸 이름 또는 앞부분 — 'defects' 면 'defects.0.qty' 도 칸 아래에서 보인다고 본다
//   403 : errorMsg = forbidden ( 기본 '권한이 없습니다.' ) · 그 밖 : 서버 message 또는 fallback
//   돌려주는 값 = 상태 코드 ( 422 면 탭을 옮기는 등 화면이 더 할 일이 있을 때 )
import { ref } from 'vue';

export function useFormErrors(shown = []) {
    const errors   = ref({});
    const errorMsg = ref('');

    const isShown = (key) => shown.some(s => key === s || key.startsWith(`${s}.`));

    function clear() {
        errors.value   = {};
        errorMsg.value = '';
    }

    function handle(e, fallback = '처리하지 못했습니다.', forbidden = '권한이 없습니다.') {
        const status = e?.response?.status;
        if (status === 422) {
            errors.value   = e.response.data.errors || {};
            errorMsg.value = Object.entries(errors.value).filter(([k]) => !isShown(k)).flatMap(([, v]) => v).join('\n');
        } else {
            errors.value   = {};
            errorMsg.value = status === 403 ? forbidden : (e?.response?.data?.message || fallback);
        }
        return status;
    }

    // 그 칸의 첫 문구 ( 없으면 '' )
    const first = (key) => errors.value[key]?.[0] ?? '';

    return { errors, errorMsg, clear, handle, first };
}

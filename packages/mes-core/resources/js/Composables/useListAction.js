// 목록에서 체크한 것으로 처리( 마감 · 삭제 … )한 뒤 다시 읽기 — 공통 실패 문구 ( 11-09 코드 검사 C-4 · 수주 · 발주 목록 공용 )
//   const { busy, run } = useListAction(load, { notFound: '수주를 찾을 수 없습니다.' });
//   await run(async () => { await axios.post(...); showFlash('...'); });
import { ref } from 'vue';
import { useToast } from '@core/Composables/useToast';

export function useListAction(reload, { notFound = '대상을 찾을 수 없습니다.' } = {}) {
    const { showFlash } = useToast();
    const busy = ref(false);

    async function run(fn) {
        busy.value = true;
        try {
            await fn();
            await reload();
        } catch (e) {
            const status = e.response?.status;
            if (status === 403)      showFlash('권한이 없습니다.', true);
            else if (status === 422) showFlash(Object.values(e.response.data.errors ?? {}).flat().join(' / '), true);
            else if (status === 404) { showFlash(notFound, true); reload(); }
            else                     showFlash('처리에 실패했습니다.', true);
        } finally {
            busy.value = false;
        }
    }
    return { busy, run };
}

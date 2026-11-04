// 자재 빠른 등록 ( config.item.quickMaterial ) — 같은 품번 · 규격 자재가 있으면 그것을 돌려준다
//   그 자재가 미사용이면 "다시 사용으로 바꿔 쓸까요?" 를 묻고, 확인하면 사용으로 바꿔 돌려준다
//   ( 품번 + 규격은 미사용 자재까지 하나뿐이라 같은 자재를 새로 만들 수 없다 — docs/품목-규격구분-설계.md )
//   돌려줌 : { item, found, reactivated } / 취소하면 { cancelled: true } — 오류( 403 · 422 )는 그대로 던진다
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message';

export async function quickMaterial(payload) {
    const url = route('config.item.quickMaterial');
    const { data } = await window.axios.post(url, payload);
    if (!data.found || data.item.is_use === 'Y') {
        return { item: data.item, found: !!data.found, reactivated: false };
    }

    const it = data.item;
    const label = `${it.item_name} [${it.item_code}]${it.spec ? ' · ' + it.spec : ''}`;
    const r = await showAlert('미사용 자재', `같은 품번 · 규격의 자재가 미사용으로 있습니다.\n${label}\n\n다시 사용으로 바꿔 쓸까요?`, 'question', 'confirm',
        { confirmButtonText: '사용으로 바꿔 쓰기' });
    if (!r.isConfirmed) {
        return { cancelled: true, item: it };
    }
    const res = await window.axios.post(url, { ...payload, reactivate: 1 });
    return { item: res.data.item, found: true, reactivated: true };
}

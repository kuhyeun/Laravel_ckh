// 외주 공정 출고 건 상태 ( 11-25 · MES_1125 #6 — 공정실적관리 개편 ) — SubconOpModal · OpResultWoModal 이 같이 쓴다
import { route } from 'ziggy-js';

// 출고 건 → 상태 ( 출고 중 · 검사 대기 · 일부 입고 · 입고 완료 · 취소 )
export function subconState(s) {
    if (s.is_canceled) return { label: '취소', cls: 'bg-gray-100 text-gray-400' };
    if (s.waiting > 0) return { label: '검사 대기', cls: 'bg-amber-50 text-amber-700' };
    if (!(s.received_qty > 0)) return { label: '출고 중', cls: 'bg-blue-50 text-blue-700' };
    if (s.left_qty > 1e-6) return { label: '일부 입고', cls: 'bg-amber-50 text-amber-700' };
    return { label: '입고 완료', cls: 'bg-green-50 text-green-700' };
}

// 이 공정의 출고 건 ( 외주 입고 화면 목록 API — 지시 번호로 찾고 공정으로 거른다 · 취소 포함 )
export async function loadSubconIssues(woNo, opIdx) {
    const { data } = await window.axios.get(route('production.subcon.receipt.issues'), {
        params: { keyword: woNo, search_field: 'wo_no', include_canceled: 1, perPage: 200, _t: Date.now() },
    });
    return (data.rows ?? []).filter(r => r.work_order_op_idx === opIdx);
}

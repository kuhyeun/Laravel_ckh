// 공정 라우팅 공정 줄 → 저장 요청 형태 ( RoutingService::lineRules 와 같은 칸 ) — 라우팅 관리 · 품목 · 수주가 같이 쓴다
export const toLinePayload = (l) => ({
    routing_line_idx: l.routing_line_idx ?? null,
    source_routing_line_idx: l.source_routing_line_idx ?? null,
    process_idx: l.process_idx === '' ? null : Number(l.process_idx),
    // 숫자 검증( min · max )에 float 를 넘기면 brick/math 경고 → 글자로
    cycle_time_sec: l.cycle_time_sec === '' || l.cycle_time_sec === null || l.cycle_time_sec === undefined ? '0' : String(l.cycle_time_sec),
    insp_first: l.insp_first, insp_middle: l.insp_middle, insp_last: l.insp_last,
    pre_approve_min: Number(l.pre_approve_min) || 0,
    pre_approved_by_design: l.pre_approved_by_design,
    remark: l.remark ?? null,
});

// 새 라우팅의 첫 줄 = 원자재출고 ( 시스템 공정 RM_OUT · 사용 중일 때만 — 지워도 된다 · 11-11 )
//   processes : 공정 선택지 ( system_key 포함 )
export const defaultLines = (processes) => {
    const rm = (processes ?? []).find(p => p.is_system === 'Y' && p.system_key === 'RM_OUT');
    return rm ? [{
        routing_line_idx: null, source_routing_line_idx: null,
        process_idx: rm.process_idx,
        cycle_time_sec: 0,
        insp_first: 'N', insp_middle: 'N', insp_last: 'N',
        pre_approve_min: 0, pre_approved_by_design: 'N', pre_approved_at: null,
        remark: '',
    }] : [];
};

// 개당 시간 합계 ( 1개 생산 표준시간 )
export const totalCycleOf = (lines) => lines.reduce((s, l) => s + (Number(l.cycle_time_sec) || 0), 0);

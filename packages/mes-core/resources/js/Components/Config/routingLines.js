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

// 개당 시간 합계 ( 1개 생산 표준시간 )
export const totalCycleOf = (lines) => lines.reduce((s, l) => s + (Number(l.cycle_time_sec) || 0), 0);

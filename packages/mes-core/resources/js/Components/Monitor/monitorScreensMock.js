// 모니터링 화면 예시 데이터 ( 11-26 · 화면 먼저 — 데이터 연결은 나중에 서버 응답으로 바꾼다 )
//   productionResultMock : 설비별 생산실적 ( 설비별 목표 · 양품 · 불량 · 시간대별 누적 · 최근 실적 )
//   productionInfoMock   : 설비별 생산정보 ( 설비마다 오늘 시작 · 오늘 생산량( 실적 합 ) · 지금 작업( 배정 / 최근 실적 / 없음 ) · 다음 작업 )
//   equipmentStatusMock  : 설비별 상태현황 ( 설비마다 지금 상태 · 언제부터 · 사유 · 오늘 상태 띠 )
//   같은 이름이면 늘 같은 값 ( 새로 그려도 흔들리지 않게 ) — rand( 씨앗 )
const rand = (seed) => { const x = Math.sin(seed * 9301 + 49297) * 233280; return x - Math.floor(x); };
const pad = (n) => String(n).padStart(2, '0');
const hm = (min) => `${pad(Math.floor(min / 60))}:${pad(min % 60)}`;
const nowMin = () => { const d = new Date(); return d.getHours() * 60 + d.getMinutes(); };

const EQUIPS = [
    ['CNC-01', 'BRACKET A', 'IT-2024-001'], ['CNC-02', 'HOUSING B-22', 'IT-2024-014'], ['CNC-03', 'FLANGE C-10', 'IT-2024-019'],
    ['CNC-04', 'SHAFT D-5', 'IT-2024-021'], ['PRESS-01', 'COVER PLATE', 'IT-2024-027'], ['PRESS-02', 'SIDE PANEL', 'IT-2024-028'],
    ['PRESS-03', 'BASE FRAME', 'IT-2024-029'], ['MILL-01', 'GEAR SHAFT', 'IT-2024-031'], ['MILL-02', 'PIN HOLDER', 'IT-2024-033'],
    ['MILL-03', 'CAM LOCK', 'IT-2024-035'], ['LATHE-01', 'BUSH RING', 'IT-2024-041'], ['LATHE-02', 'SPACER 12T', 'IT-2024-044'],
    ['LATHE-03', 'NUT M12', 'IT-2024-046'], ['WELD-01', 'BRACKET ASSY', 'IT-2024-051'], ['WELD-02', 'FRAME ASSY', 'IT-2024-052'],
    ['PAINT-01', 'COVER PLATE', 'IT-2024-027'], ['GRIND-01', 'GEAR SHAFT', 'IT-2024-031'], ['DRILL-01', 'BASE FRAME', 'IT-2024-029'],
    ['ASSY-01', 'MAIN UNIT', 'IT-2024-061'], ['PACK-01', '출하 포장', 'IT-2024-090'],
];
const PROCS = ['010 절단', '020 가공', '030 가공', '040 용접', '050 도장', '060 조립'];
const WORKERS = ['김작업', '이현장', '박설비', '최생산', '정품질', '한조립', '윤가공', '장용접'];

// ---- 설비별 생산실적 ----
export const productionResultMock = () => {
    const nowH = new Date().getHours();
    const equipments = EQUIPS.map(([name, item], i) => {
        const target = Math.round((300 + rand(i + 1) * 900) / 10) * 10;
        const made   = Math.round(target * (0.35 + rand(i + 11) * 0.6));
        const bad    = Math.round(made * rand(i + 21) * 0.06);
        return { name, item, target, good: made - bad, bad };
    });
    // 시간대별 ( 06 ~ 21시 ) 누적 — 목표는 고르게 · 실적은 출근 · 점심 · 퇴근 굴곡
    const total = equipments.reduce((s, e) => s + e.target, 0);
    const span = Array.from({ length: 16 }, (_, k) => k + 6);
    let acc = 0;
    const hourly = span.map((h) => {
        const planAcc = Math.round(total * (h - 5) / 16);
        const w = h === 6 ? 0.5 : h === 12 ? 0.55 : 1;
        if (h <= nowH) acc += Math.round(total / 16 * w * (0.7 + rand(h) * 0.3));
        return { h, plan: planAcc, actual: h <= nowH ? acc : null };
    });
    // 최근 실적 ( 새것이 위 )
    const now = nowMin();
    const feed = Array.from({ length: 12 }, (_, k) => {
        const e = EQUIPS[Math.floor(rand(k + 101) * EQUIPS.length)];
        return { id: k, at: hm(Math.max(0, now - k * 7 - Math.floor(rand(k + 5) * 5))), equip: e[0], item: e[1], qty: 10 * (1 + Math.floor(rand(k + 55) * 8)),
            bad: rand(k + 77) < 0.2 ? 1 + Math.floor(rand(k + 9) * 3) : 0, worker: WORKERS[k % WORKERS.length] };
    });
    return { equipments, hourly, feed };
};
// 새 실적 한 줄 ( 피드가 흐르는 모습 — 예시 )
let feedSeq = 1000;
export const nextFeed = () => {
    const k = feedSeq++;
    const e = EQUIPS[Math.floor(rand(k) * EQUIPS.length)];
    return { id: k, at: hm(nowMin()), equip: e[0], item: e[1], qty: 10 * (1 + Math.floor(rand(k + 3) * 8)), bad: rand(k + 7) < 0.2 ? 1 : 0, worker: WORKERS[k % WORKERS.length] };
};

// ---- 설비별 생산정보 ----
//   늘 : 상태 · 오늘 시작( 오늘 첫 실적 ) · 오늘 생산량( 오늘 실적 합 — 양품 / 불량 )
//   지금 작업 source :
//     ASSIGNED = 이 설비가 배정된 진행 중 공정 — 작업지시 · 품목 · 공정 · 지시 수량 대비 실적 · 다음 작업( 배정된 대기 공정 )
//     RECENT   = 배정은 없고 오늘 이 설비로 등록한 마지막 실적의 작업지시 · 품목 · 공정 ( 지시 수량 진행은 안 보임 )
//     NONE     = 연결된 작업지시 없음 ( 가동 중이면 「가동 중 · 작업지시 연결 없음」 )
//   예상 완료 시각은 쓰지 않는다 ( 11-26 — 추정이 틀리면 화면을 믿지 않게 된다 )
export const productionInfoMock = () => {
    const now = nowMin();
    return EQUIPS.map(([name, item, code], i) => {
        const state = [3, 12, 17].includes(i) ? 'STOP' : [1, 8, 10, 15].includes(i) ? 'IDLE' : 'RUN';   // 상태현황 예시와 같게 ( 점검은 여기선 대기 )
        const source = state === 'IDLE' ? 'NONE' : [5, 13].includes(i) ? 'NONE' : [2, 9, 16, 19].includes(i) ? 'RECENT' : 'ASSIGNED';
        const worked = state !== 'IDLE' || i === 8;   // 대기여도 오전에 일한 설비는 생산량이 있다
        const made = worked ? Math.round((80 + rand(i + 13) * 700) / 5) * 5 : 0;
        const bad = Math.round(made * rand(i + 23) * 0.05);
        const target = Math.round((made * (1.1 + rand(i + 3) * 1.5)) / 10) * 10;
        const n1 = EQUIPS[(i + 3) % EQUIPS.length], n2 = EQUIPS[(i + 7) % EQUIPS.length];
        const job = source === 'NONE' ? null : {
            wo_no: `WO-261126-${pad(i + 1)}${i % 3}-0${1 + (i % 2)}`, item, code, process: PROCS[i % PROCS.length], worker: WORKERS[i % WORKERS.length],
            ...(source === 'ASSIGNED' ? { target, done: Math.min(target, made + Math.round(rand(i + 5) * 200)) } : {}),   // 공정 누적 실적 ( 어제 것까지 )
        };
        return {
            name, state, source, job,
            started_at: worked ? hm(Math.max(360, now - 60 - Math.floor(rand(i + 17) * 400))) : null,
            today: { good: made - bad, bad },
            next: source !== 'ASSIGNED' && state !== 'IDLE' ? [] : [
                { wo_no: `WO-261126-${pad(i + 4)}1-01`, item: n1[1], qty: 100 * (1 + (i % 5)) },
                ...(rand(i + 41) < 0.6 ? [{ wo_no: `WO-261127-${pad(i + 2)}2-01`, item: n2[1], qty: 50 * (2 + (i % 4)) }] : []),
            ],
        };
    });
};

// ---- 설비별 상태현황 ----
//   상태 : RUN 가동 · IDLE 대기 · STOP 정지 · MAINT 점검 — 띠는 06:00 부터 지금까지 구간
const STOP_REASONS = ['자재 대기', '금형 교체', '설비 이상', '작업자 부재', '품질 확인'];
export const equipmentStatusMock = () => {
    const now = nowMin();
    return EQUIPS.map(([name], i) => {
        const segs = [];
        let t = 360;   // 06:00
        let k = 0;
        while (t < now) {
            const r = rand(i * 17 + k);
            const state = k === 0 ? 'RUN' : r < 0.62 ? 'RUN' : r < 0.78 ? 'IDLE' : r < 0.93 ? 'STOP' : 'MAINT';
            const len = state === 'RUN' ? 40 + Math.floor(rand(i + k * 3) * 120) : 8 + Math.floor(rand(i + k * 5) * 40);
            const to = Math.min(now, t + len);
            segs.push({ from: t, to, state, reason: state === 'STOP' ? STOP_REASONS[(i + k) % STOP_REASONS.length] : state === 'MAINT' ? '정기 점검' : null });
            t = to; k++;
        }
        // 지금 상태는 몇 대만 정지 · 점검 · 대기로 ( 예시 — 상태가 골고루 보이게 )
        const cur = [3, 12, 17].includes(i) ? 'STOP' : [8].includes(i) ? 'MAINT' : [1, 10, 15].includes(i) ? 'IDLE' : 'RUN';
        const last = segs[segs.length - 1];
        if (last && last.state !== cur) {
            const cut = Math.max(last.from + 1, now - (6 + Math.floor(rand(i + 3) * 50)));
            last.to = cut;
            segs.push({ from: cut, to: now, state: cur, reason: cur === 'STOP' ? STOP_REASONS[i % STOP_REASONS.length] : cur === 'MAINT' ? '정기 점검' : null });
        }
        const curSeg = segs[segs.length - 1];
        const runMin = segs.filter(s => s.state === 'RUN').reduce((s, x) => s + (x.to - x.from), 0);
        return {
            name, state: cur, since: hm(curSeg.from), minutes: now - curSeg.from, reason: curSeg.reason,
            rate: Math.round(runMin / Math.max(1, now - 360) * 100),
            segs: segs.map(s => ({ ...s, fromText: hm(s.from), toText: hm(s.to) })),
        };
    });
};
export const STATUS_LABEL = { RUN: '가동', IDLE: '대기', STOP: '정지', MAINT: '점검' };

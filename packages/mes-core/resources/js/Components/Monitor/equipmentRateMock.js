// 설비별 가동률 예시 데이터 ( 11-26 · 화면 먼저 — 데이터 연결은 나중에 )
//   hourly : 설비별 시간별 가동률 ( 0 ~ 23시 · % · 아직 오지 않은 시간은 null )
//   ( 오늘 생산 계획은 생산종합현황과 같은 데이터 — productionMock.plans )
//   예시는 32대 — 히트맵이 한 화면을 넘을 때( 장 넘김 )를 보려고 설비 이름 · 값을 만들어 쓴다
const NAMES = [
    'CNC-01', 'CNC-02', 'CNC-03', 'CNC-04', 'CNC-05', 'CNC-06', 'PRESS-01', 'PRESS-02', 'PRESS-03', 'PRESS-04',
    'MILL-01', 'MILL-02', 'MILL-03', 'MILL-04', 'LATHE-01', 'LATHE-02', 'LATHE-03', 'LATHE-04', 'LATHE-05', 'WELD-01',
    'WELD-02', 'WELD-03', 'PAINT-01', 'PAINT-02', 'GRIND-01', 'GRIND-02', 'DRILL-01', 'DRILL-02', 'TAP-01', 'ASSY-01',
    'ASSY-02', 'PACK-01',
];
// 같은 이름이면 늘 같은 값 ( 새로 그려도 흔들리지 않게 )
const rand = (seed) => { const x = Math.sin(seed * 9301 + 49297) * 233280; return x - Math.floor(x); };

export const equipmentRateMock = () => {
    const nowH = new Date().getHours();
    const hourly = {};
    NAMES.forEach((name, i) => {
        const base = 55 + rand(i + 1) * 40;                 // 설비마다 평소 가동률
        const stopFrom = rand(i + 7) < 0.18 ? 10 + Math.floor(rand(i + 3) * 6) : 99;   // 몇 대는 중간에 멈춤
        hourly[name] = Array.from({ length: 24 }, (_, h) => {
            if (h > nowH) return null;                      // 아직 오지 않은 시간
            if (h < 6 || h > 21 || h >= stopFrom) return 0;   // 일하지 않는 시간 · 멈춘 뒤
            const ramp = h === 6 ? 0.45 : h === 12 ? 0.55 : h >= 20 ? 0.35 : 1;   // 출근 · 점심 · 퇴근
            return Math.max(0, Math.min(100, Math.round(base * ramp + (rand(i * 31 + h) - 0.5) * 18)));
        });
    });
    return { hourly };
};

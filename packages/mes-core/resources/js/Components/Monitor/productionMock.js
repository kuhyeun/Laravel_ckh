// 생산종합현황 예시 데이터 ( 11-26 · 화면 먼저 — 데이터 연결은 나중에 서버 응답으로 바꾼다 )
//   equipments : 설비 카드 ( good 양품 · bad 불량 — 오늘 실적 합 · running 가동 여부 · item 지금 품목 ) — 목표는 쓰지 않는다 ( 11-26 )
//                item 은 작업지시와 연결된 때만 ( 배정된 진행 중 공정 · 없으면 오늘 그 설비의 마지막 실적 ) — 연결이 없으면 빈 값
//   plans      : 오늘 생산 계획
//   ( 회사 · 공지는 모니터링 공통 — monitorMock )
export const productionMock = () => ({
    equipments: [
        { name: 'CNC-01', good: 921, bad: 19, item: 'BRACKET A',     running: true  },
        { name: 'CNC-02', good: 691, bad: 29, item: 'HOUSING B-22',  running: true  },
        { name: 'CNC-03', good: 450, bad: 5, item: 'FLANGE C-10',   running: true  },
        { name: 'PRESS-01', good: 592, bad: 18, item: 'COVER PLATE',   running: true  },
        { name: 'PRESS-02', good: 136, bad: 14, item: 'SIDE PANEL',    running: false },
        { name: 'PRESS-03', good: 502, bad: 10, item: 'BASE FRAME',    running: true  },
        { name: 'MILL-01', good: 470, bad: 0, item: 'GEAR SHAFT',    running: true  },
        { name: 'MILL-02', good: 314, bad: 16, item: '',              running: true  },   // 가동 중이지만 작업지시 연결 없음 ( 예시 )
        { name: 'MILL-03', good: 110, bad: 10, item: 'CAM LOCK',      running: false },
        { name: 'LATHE-01', good: 0, bad: 0,   item: '',              running: false },
        { name: 'LATHE-02', good: 574, bad: 6, item: 'BUSH RING',     running: true  },
        { name: 'LATHE-03', good: 390, bad: 12, item: 'SPACER 12T',    running: true  },
        { name: 'WELD-01', good: 210, bad: 4, item: 'BRACKET ASSY',  running: true  },
        { name: 'WELD-02', good: 83, bad: 5,  item: '',              running: true  },
        { name: 'PAINT-01', good: 1300, bad: 20, item: 'COVER PLATE',  running: true  },
        { name: 'PACK-01', good: 1642, bad: 8, item: '출하 포장',     running: true  },
    ],
    plans: [
        { equip: 'CNC-01',   item: 'BRACKET A',    code: 'IT-2024-001', qty: 1200, due: '2026-11-27' },
        { equip: 'CNC-02',   item: 'HOUSING B-22', code: 'IT-2024-014', qty: 1000, due: '2026-11-27' },
        { equip: 'CNC-03',   item: 'FLANGE C-10',  code: 'IT-2024-019', qty: 900,  due: '2026-11-27' },
        { equip: 'PRESS-01', item: 'COVER PLATE',  code: 'IT-2024-027', qty: 800,  due: '2026-11-28' },
        { equip: 'PRESS-03', item: 'BASE FRAME',   code: 'IT-2024-029', qty: 700,  due: '2026-11-28' },
        { equip: 'MILL-01',  item: 'GEAR SHAFT',   code: 'IT-2024-031', qty: 500,  due: '2026-11-28' },
        { equip: 'LATHE-02', item: 'BUSH RING',    code: 'IT-2024-042', qty: 600,  due: '2026-11-29' },
        { equip: 'LATHE-03', item: 'SPACER 12T',   code: 'IT-2024-044', qty: 640,  due: '2026-11-29' },
        { equip: 'WELD-01',  item: 'BRACKET ASSY', code: 'IT-2024-051', qty: 300,  due: '2026-11-30' },
    ],
});

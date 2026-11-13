// 색 — 스타일 가이드 §7-1 ( JS 에서 HEX 가 필요할 때 여기서만 가져온다 · 값 = Tailwind 기본 팔레트 )
//   화면 template 은 클래스( text-gray-400 ) · CSS 는 theme('colors.gray.400') — 어디서도 HEX 를 손으로 치지 않는다
//   단계가 더 필요하면 이 표 · 스타일 가이드 표에 같이 올린다
export const COLORS = {
    white:    '#ffffff',

    gray50:   '#f9fafb',
    gray100:  '#f3f4f6',
    gray200:  '#e5e7eb',
    gray300:  '#d1d5db',
    gray400:  '#9ca3af',
    gray500:  '#6b7280',
    gray600:  '#4b5563',
    gray700:  '#374151',
    gray800:  '#1f2937',

    blue50:   '#eff6ff',
    blue200:  '#bfdbfe',
    blue300:  '#93c5fd',
    blue400:  '#60a5fa',
    blue500:  '#3b82f6',
    blue600:  '#2563eb',
    blue700:  '#1d4ed8',
    blue800:  '#1e40af',
    blue950:  '#172554',   // 옅은 파랑 바탕 위 글자 ( 달력 막대 — 11-12 )

    red50:    '#fef2f2',
    red500:   '#ef4444',
    red600:   '#dc2626',
    red700:   '#b91c1c',
    red800:   '#991b1b',

    amber50:  '#fffbeb',
    amber200: '#fde68a',
    amber300: '#fcd34d',   // 옅은 강조 바탕 ( 예 : 달력 막대 — 어두운 글자 ) · 11-05 예시로 추가
    amber400: '#fbbf24',
    amber600: '#d97706',
    amber700: '#b45309',
    amber900: '#78350f',   // 노랑 바탕 위 글자 ( 달력 막대 — 11-12 )

    green50:  '#f0fdf4',
    green200: '#bbf7d0',
    green600: '#16a34a',
    green700: '#15803d',
    green950: '#052e16',   // 초록 바탕 위 글자 ( 달력 막대 — 11-12 )

    sky100:   '#e0f2fe',   // 그리드 고른 행( row-selected ) — 이 하나만 다른 계열
};

// 상태 색 ( 스타일 가이드 §7 상태 색 ) — 모듈 *Codes.js 가 이것을 쓴다
export const STATUS_COLOR = {
    idle:      COLORS.gray500,    // 시작 전 : 대기 · 미확정
    active:    COLORS.blue400,    // 진행 · 확정
    done:      COLORS.green600,   // 끝남 : 완료
    attention: COLORS.amber300,   // 손이 필요함 : 검사대기 · 결재 · 미달
    bad:       COLORS.red600,     // 불합격 · 반려 · 지연
    off:       COLORS.gray400,    // 취소 · 미사용
};

// 차트 계열색 — 화면 상태색과 섞이지 않게 따로 ( 일부러 팔레트 밖 · §7-1 )
export const CHART = {
    blue:   '#2a78d6',
    orange: '#eb6834',
    green:  '#1baf7a',
    violet: '#8a5cd6',   // 4번째 범주 ( 외주 검사 )
    faded:  '#c9d9ee',   // 고르지 않은 막대
    ink:    '#0b0b0b',   // 축 · 제목 글자
};

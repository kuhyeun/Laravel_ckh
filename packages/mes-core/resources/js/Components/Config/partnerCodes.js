// 거래처 구분 ( 멀티 선택 — 한 거래처가 여러 구분일 수 있음 )
//   서버 Partner::ROLE_COLUMNS 와 동일하게 유지

export const PARTNER_ROLES = [
    { key: 'is_customer', role: 'customer', label: '고객사' },
    { key: 'is_supplier', role: 'supplier', label: '구매처' },
    { key: 'is_subcon',   role: 'subcon',   label: '외주처' },
];

// 행의 구분을 '구매처 · 고객사' 형태로
export const partnerRoleText = (row) =>
    PARTNER_ROLES.filter(r => row?.[r.key] === 'Y').map(r => r.label).join(' · ');

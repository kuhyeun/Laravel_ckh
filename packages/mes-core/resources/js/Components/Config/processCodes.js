// 공정 구분값 ( 서버 Process::TYPES · INSPECTION_KINDS 와 동일하게 유지 )

export const PROCESS_TYPES = [
    { value: 'PRODUCTION', label: '생산' },
    { value: 'INSPECTION', label: '검사' },
    { value: 'EQUIPMENT',  label: '설비생산' },   // 사용 가능 설비 1개 이상 필수
    { value: 'SUBCON',     label: '외주' },
];

export const INSPECTION_KINDS = [
    { value: 'NORMAL', label: '일반' },
    { value: 'IFL',    label: '초중종' },
];

export const processTypeLabel    = (value) => PROCESS_TYPES.find(t => t.value === value)?.label ?? value;
export const inspectionKindLabel = (value) => INSPECTION_KINDS.find(k => k.value === value)?.label ?? (value ?? '');

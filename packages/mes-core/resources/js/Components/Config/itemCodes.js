// 품목 구분값 ( 서버 Item::KINDS_BY_TYPE 과 동일하게 유지 )
//   구분 기준은 "단독으로 파는가" — 단독 판매 FINISHED / 사내 소비 SEMI / 미조립 세트 SET

export const ITEM_TYPES = [
    { value: 'MATERIAL', label: '자재' },
    { value: 'PRODUCT',  label: '제품' },
];

export const ITEM_KINDS = {
    MATERIAL: [
        { value: 'RAW',   label: '원자재' },
        { value: 'STOCK', label: '원소재' },   // 환봉·코일·판재 등 잘라 쓰는 소재
    ],
    PRODUCT: [
        { value: 'FINISHED', label: '완제품' },
        { value: 'SEMI',     label: '반제품' },
        { value: 'SET',      label: '세트' },   // 미조립 세트 ( 포장공정 하나짜리 라우팅 )
    ],
};

export const itemTypeLabel = (value) => ITEM_TYPES.find(t => t.value === value)?.label ?? value;

export const itemKindLabel = (value) =>
    Object.values(ITEM_KINDS).flat().find(k => k.value === value)?.label ?? value;

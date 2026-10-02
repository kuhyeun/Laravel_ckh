<?php

namespace MesCore\Config\Validation;

use MesCore\Config\Models\Partner;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 거래처 기본 정보 검증 — 화면 단건 저장( PartnerController::save )과 엑셀 일괄 등록( PartnerImport )이 같이 쓴다
 *   ( 담당자 · 출고지 · 정책 덮어쓰기는 화면 전용이라 PartnerController 에 남는다 )
 */
class PartnerRules {

    public const ATTRIBUTES = [
        'partner_code' => '거래처코드',
        'partner_name' => '거래처명',
        'is_supplier'  => '구매처',
        'is_customer'  => '고객사',
        'is_subcon'    => '외주처',
        'biz_no'       => '사업자번호',
        'ceo_name'     => '대표자',
        'is_use'       => '사용 여부',
        'remark'       => '비고',
    ];

    public static function rules(Partner $partner): array {
        return [
            'partner_code' => ['required', 'string', 'max:20', Rule::unique('partner', 'partner_code')->ignore($partner->partner_idx, 'partner_idx')],
            'partner_name' => 'required|string|max:60',
            'is_supplier'  => 'required|in:Y,N',
            'is_customer'  => 'required|in:Y,N',
            'is_subcon'    => 'required|in:Y,N',
            'biz_no'       => 'nullable|string|max:20',
            'ceo_name'     => 'nullable|string|max:50',
            'is_use'       => 'required|in:Y,N',
            'remark'       => 'nullable|string|max:2000',
        ];
    }

    // E_CUSTOMER_ROLE_NONE : 구분( 구매처 · 고객사 · 외주처 )은 하나 이상
    public static function assertBusiness(array $v): void {
        if (collect(Partner::ROLE_COLUMNS)->every(fn ($col) => ($v[$col] ?? 'N') === 'N')) {
            throw ValidationException::withMessages(['roles' => ['구매처 · 고객사 · 외주처 중 하나 이상 선택하세요.']]);
        }
    }

    // 입고가 있는 거래처( 구매처 · 외주처 )만 입고검사 덮어쓰기를 둔다
    public static function hasIncoming(array $v): bool {
        return ($v['is_supplier'] ?? 'N') === 'Y' || ($v['is_subcon'] ?? 'N') === 'Y';
    }
}

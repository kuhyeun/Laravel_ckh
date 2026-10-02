<?php

namespace MesCore\Config\Validation;

use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Routing;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 품목 기본 정보 검증 — 화면 단건 저장( ItemController::save )과 엑셀 일괄 등록( ItemImport )이 같이 쓴다
 *   두 곳의 결과가 달라지지 않도록 규칙은 여기 한 곳에만 둔다
 *   ( 주 자재 · 정책 덮어쓰기 · 리드타임은 화면 전용이라 ItemController 에 남는다 )
 */
class ItemRules {

    public const ATTRIBUTES = [
        'item_code'        => '품번',
        'item_name'        => '품명',
        'item_type'        => '대분류',
        'item_kind'        => '소분류',
        'spec_unit'        => '단위',
        'spec'             => '규격',
        'serial_use'       => '시리얼 사용',
        'pack_qty'         => '포장 수량',
        'safety_stock'     => '안전재고',
        'order_cycle_days' => '발주 주기',
        'moq'              => '최소 수량',
        'unit_round'       => '배수',
        'spec_dim'         => '규격 차원',
        'min_usable_spec'  => '최소 사용 규격',
        'calc_code'        => '계산식',
        'std_sale_price'   => '판매단가',
        'std_buy_price'    => '발주단가',
        'std_subcon_price' => '외주단가',
        'is_use'           => '사용 여부',
        'remark'           => '비고',
        'routing_idx'      => '기본 라우팅',
    ];

    public const MESSAGES = [
        'item_kind.in' => '대분류에 맞지 않는 소분류입니다.',
        'spec_dim.in'  => '현재는 1차원 규격만 지원합니다.',
    ];

    // 비어 있으면 0 으로 채우는 NOT NULL 숫자 칸
    private const ZERO_DEFAULTS = ['safety_stock', 'order_cycle_days', 'moq', 'unit_round', 'std_sale_price', 'std_buy_price', 'std_subcon_price'];

    // 기본 정보 규칙 ( $item : 수정이면 기존 품목, 신규면 new Item )
    public static function rules(Item $item, ?string $type): array {
        return [
            'item_code' => ['required', 'string', 'max:40', Rule::unique('item', 'item_code')->ignore($item->item_idx, 'item_idx')],
            'item_name' => 'required|string|max:100',
            'item_type' => ['required', Rule::in(array_keys(Item::KINDS_BY_TYPE))],
            // E_ITEM_KIND_MISMATCH : 대분류에 속하지 않는 소분류
            'item_kind' => ['required', Rule::in(Item::KINDS_BY_TYPE[$type] ?? [])],
            'spec_unit' => [
                'bail', 'required', 'string', 'max:100',
                // 단위는 코드 마스터에서만 ( 자유 입력 금지 )
                Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', Item::UNIT_CODE_GROUP),
                // 새로 고르는 단위는 사용 중이어야 함 ( 기존 값이 미사용으로 내려간 경우는 그대로 저장 허용 )
                function ($attribute, $value, $fail) use ($item) {
                    if ($item->exists && $item->spec_unit === $value) {
                        return;
                    }
                    $active = SystemCode::where('system_code_group_key', Item::UNIT_CODE_GROUP)
                        ->where('system_code_key', $value)
                        ->where('is_use', 'Y')
                        ->exists();
                    if (!$active) {
                        $fail('사용 중인 단위를 선택하세요.');
                    }
                },
            ],
            'spec'             => 'nullable|string|max:100',
            'serial_use'       => 'required|in:Y,N',
            'pack_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'safety_stock'     => 'nullable|numeric|min:0|max:999999999999',
            'order_cycle_days' => 'nullable|integer|min:0|max:3650',
            'moq'              => 'nullable|numeric|min:0|max:999999999999',
            'unit_round'       => 'nullable|numeric|min:0|max:999999999999',
            // E_SPEC_DIM : Phase 1 은 1차원만
            'spec_dim'         => 'nullable|in:1',
            'min_usable_spec'  => 'nullable|numeric|min:0|max:999999999999',
            'calc_code'        => 'nullable|string|max:30',
            'std_sale_price'   => 'nullable|numeric|min:0|max:9999999999999',
            'std_buy_price'    => 'nullable|numeric|min:0|max:9999999999999',
            'std_subcon_price' => 'nullable|numeric|min:0|max:9999999999999',
            'is_use'           => 'required|in:Y,N',
            'remark'           => 'nullable|string|max:2000',
            // 기본 라우팅 : 등록 시점에만 자동 적용되고 이후에는 수동 ( 소급 없음 )
            'routing_idx'      => 'nullable|integer|exists:routing,routing_idx',
        ];
    }

    // 검증 뒤 저장 전 정리 ( 빈 숫자 → 0, 소재 항목은 원소재만 )
    public static function normalize(array $v): array {
        foreach (self::ZERO_DEFAULTS as $field) {
            $v[$field] = $v[$field] ?? 0;
        }
        $v['spec_dim'] = $v['spec_dim'] ?? 1;

        // 소재 항목은 원소재( STOCK )만 의미가 있음 → 그 외는 비움
        if (($v['item_kind'] ?? null) !== 'STOCK') {
            $v['min_usable_spec'] = null;
            $v['calc_code']       = null;
        }
        return $v;
    }

    // 규칙으로 못 거르는 업무 조건 ( 실패는 ValidationException )
    public static function assertBusiness(Item $item, array $v): void {
        // 시리얼은 기능 스위치( feature.serial )가 켜져야 새로 켤 수 있다 ( 이미 켜진 품목은 유지 · 끄기 허용 )
        if (($v['serial_use'] ?? 'N') === 'Y' && $item->serial_use !== 'Y' && !app(PolicyService::class)->isEnabled('feature.serial')) {
            throw ValidationException::withMessages(['serial_use' => ['시리얼 번호 기능이 꺼져 있어 켤 수 없습니다.']]);
        }

        // 새로 고르는 라우팅은 사용 중이어야 함 ( 기존 값이 미사용으로 내려간 경우는 유지 허용 )
        $routingIdx = $v['routing_idx'] ?? null;
        if ($routingIdx && (int) $routingIdx !== (int) $item->routing_idx
            && Routing::where('routing_idx', $routingIdx)->where('is_use', 'N')->exists()) {
            throw ValidationException::withMessages(['routing_idx' => ['사용 중인 라우팅을 선택하세요.']]);
        }
    }
}

<?php

namespace MesCore\Config\Imports;

use MesCore\Basic\Services\Import\ImportHandler;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Partner;
use MesCore\Config\Validation\PartnerRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

// 거래처 엑셀 일괄 등록 ( 양식 partner.import )
//   기본 정보만 — 담당자 · 출고지 · 입고검사 덮어쓰기는 화면에서 ( 수정할 때도 건드리지 않는다 )
//   단, 화면 저장과 같이 구매처 · 외주처가 아니게 되면 입고검사 덮어쓰기를 해제한다
class PartnerImport implements ImportHandler {

    private const FIELDS = ['partner_code', 'partner_name', 'is_supplier', 'is_customer', 'is_subcon', 'biz_no', 'ceo_name', 'is_use', 'remark'];

    public function entity(): string {
        return 'partner';
    }

    public function keyField(): string {
        return 'partner_code';
    }

    public function serverRequired(): array {
        return ['partner_name'];
    }

    public function find(string $key): ?Model {
        return Partner::where('partner_code', $key)->first();
    }

    // 신규 기본값 : 화면 등록과 같게 ( 고객사만 켜짐 )
    public function baseData(?Model $existing): array {
        if ($existing) {
            return $existing->only(self::FIELDS);
        }
        return ['is_supplier' => 'N', 'is_customer' => 'Y', 'is_subcon' => 'N', 'is_use' => 'Y'];
    }

    public function resolveRef(string $ref, string $text): mixed {
        return null;
    }

    public function validate(array $data, ?Model $existing, array $attributes): array {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $validated = Validator::make($data, PartnerRules::rules($existing ?? new Partner()), [], $attributes + PartnerRules::ATTRIBUTES)
            ->validate();
        PartnerRules::assertBusiness($validated);
        return $validated;
    }

    public function save(array $validated, ?Model $existing): Model {
        $partner = $existing ?? new Partner();
        $partner->fill($validated)->save();

        if (!PartnerRules::hasIncoming($validated)) {
            app(PolicyService::class)->applyEntity(['quality.incoming_inspection'], ['quality.incoming_inspection' => null], 'PARTNER', $partner->partner_idx);
        }
        return $partner;
    }
}

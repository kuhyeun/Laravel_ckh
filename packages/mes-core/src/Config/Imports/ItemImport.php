<?php

namespace MesCore\Config\Imports;

use MesCore\Basic\Services\Import\ImportHandler;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Routing;
use MesCore\Config\Validation\ItemRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

// 품목 엑셀 일괄 등록 ( 양식 item.import )
//   기본 정보만 — 주 자재( BOM ) · 정책 덮어쓰기 · 리드타임은 화면에서
//   검증 · 정리 · 업무 조건은 화면 단건 저장과 같은 ItemRules
class ItemImport implements ImportHandler {

    // 엑셀로 다루는 칸 ( 이 밖의 칸은 수정할 때 건드리지 않는다 )
    private const FIELDS = [
        'item_code', 'item_name', 'item_type', 'item_kind', 'spec_unit', 'spec', 'material', 'routing_idx', 'serial_use',
        'pack_qty', 'safety_stock', 'order_cycle_days', 'moq', 'unit_round', 'spec_dim', 'min_usable_spec', 'calc_code',
        'std_sale_price', 'std_buy_price', 'std_subcon_price', 'is_use', 'remark',
    ];

    public function entity(): string {
        return 'item';
    }

    public function keyField(): string {
        return 'item_code';
    }

    public function serverRequired(): array {
        return ['item_name', 'item_type', 'item_kind', 'spec_unit'];
    }

    public function find(string $key): ?Model {
        return Item::where('item_code', $key)->first();
    }

    // 수정 : 기존 값 / 신규 : 화면 등록과 같은 기본값 ( 기본 라우팅 자동 적용 포함 )
    public function baseData(?Model $existing): array {
        if ($existing) {
            return $existing->only(self::FIELDS);
        }
        return [
            'serial_use'  => 'N',
            'is_use'      => 'Y',
            'routing_idx' => Routing::where('is_default', 'Y')->where('is_use', 'Y')->value('routing_idx'),
        ];
    }

    // 기본 라우팅 : 라우팅코드 또는 라우팅명
    public function resolveRef(string $ref, string $text): mixed {
        if ($ref !== 'routing') {
            return null;
        }
        return Routing::where('routing_code', $text)->value('routing_idx')
            ?? Routing::where('routing_name', $text)->value('routing_idx');
    }

    public function validate(array $data, ?Model $existing, array $attributes): array {
        $item = $existing ?? new Item();
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        $validated = Validator::make($data, ItemRules::rules($item, $data['item_type'] ?? null), ItemRules::MESSAGES, $attributes + ItemRules::ATTRIBUTES)
            ->validate();
        $validated = ItemRules::normalize($validated);
        ItemRules::assertBusiness($item, $validated);

        return $validated;
    }

    public function save(array $validated, ?Model $existing): Model {
        $item = $existing ?? new Item();
        $item->fill($validated)->save();
        Item::refreshCycleTime([$item->item_idx]);   // 라우팅이 바뀌었을 수 있다
        return $item;
    }
}

<?php

namespace MesCore\Config\Imports;

use MesCore\Basic\Services\Import\ImportHandler;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Routing;
use MesCore\Config\Services\RoutingService;
use MesCore\Config\Validation\ItemRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

// 품목 엑셀 일괄 등록 ( 양식 item.import )
//   기본 정보만 — 주 자재( BOM ) · 정책 덮어쓰기 · 리드타임은 화면에서
//   검증 · 정리 · 업무 조건은 화면 단건 저장과 같은 ItemRules
//   라우팅 칸 = 틀( ① ) — 저장하면 품목 전용 라우팅( ② )으로 복사 · 틀이 그대로면 ② 는 손대지 않는다 ( 화면에서 고친 공정 보존 )
//   같은 품목 찾기 : 자재 = 품번 + 규격( specKey ) / 제품 = 품번 ( docs/품목-규격구분-설계.md )
class ItemImport implements ImportHandler {

    private const SEP = "\x1F";   // 행 열쇠의 품번 · 규격 구분

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

    // 행 열쇠 : 자재 = 품번 + 규격 · 제품 = 품번
    public function rowKey(array $converted): string {
        $code = (string) ($converted['item_code'] ?? '');
        return ($converted['item_type'] ?? null) === 'MATERIAL'
            ? $code . self::SEP . Item::specKey($converted['spec'] ?? null)
            : $code;
    }

    public function keyLabel(string $key): string {
        [$code, $spec] = array_pad(explode(self::SEP, $key, 2), 2, null);
        return $spec === null ? $code : ($spec === '' ? "{$code} ( 규격 없음 )" : "{$code} / {$spec}");
    }

    public function find(string $key): ?Model {
        [$code, $spec] = array_pad(explode(self::SEP, $key, 2), 2, null);
        return $spec === null
            ? Item::where('item_code', $code)->where('item_type', '!=', 'MATERIAL')->first() ?? Item::where('item_code', $code)->first()
            : Item::where('item_code', $code)->where('spec_key', $spec)->where('item_type', 'MATERIAL')->first();
    }

    // 수정 : 기존 값( 라우팅은 품목 라우팅의 틀 ) / 신규 : 화면 등록과 같은 기본값 ( 기본 틀 자동 적용 포함 )
    public function baseData(?Model $existing): array {
        if ($existing) {
            return array_merge($existing->only(self::FIELDS), [
                'routing_idx' => app(RoutingService::class)->itemRouting($existing->item_idx)?->base_routing_idx,
            ]);
        }
        return [
            'serial_use'  => 'N',
            'is_use'      => 'Y',
            'routing_idx' => Routing::templates()->where('is_default', 'Y')->where('is_use', 'Y')->value('routing_idx'),
        ];
    }

    // 라우팅 틀 : 라우팅코드 또는 라우팅명 ( 틀에서만 찾는다 )
    public function resolveRef(string $ref, string $text): mixed {
        if ($ref !== 'routing') {
            return null;
        }
        return Routing::templates()->where('routing_code', $text)->value('routing_idx')
            ?? Routing::templates()->where('routing_name', $text)->value('routing_idx');
    }

    public function validate(array $data, ?Model $existing, array $attributes): array {
        $item = $existing ?? new Item();
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        $validated = Validator::make($data, ItemRules::rules($item, $data['item_type'] ?? null, $data['spec'] ?? null), ItemRules::MESSAGES, $attributes + ItemRules::ATTRIBUTES)
            ->validate();
        $validated = ItemRules::normalize($validated);
        ItemRules::assertBusiness($item, $validated);

        return $validated;
    }

    public function save(array $validated, ?Model $existing): Model {
        $item        = $existing ?? new Item();
        $templateIdx = $validated['routing_idx'] ?? null;
        unset($validated['routing_idx']);   // item.routing_idx 는 품목 라우팅( ② ) — 서비스가 맞춘다
        $item->fill($validated)->save();
        app(\MesCore\Config\Services\SpecCodeService::class)->ensure($item->spec);   // 없는 규격은 규격 코드에 더한다
        app(RoutingService::class)->syncItemRouting($item, $templateIdx ? (int) $templateIdx : null, null);
        return $item;
    }
}

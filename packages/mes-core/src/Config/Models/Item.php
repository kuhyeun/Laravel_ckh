<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 품목 마스터 ( BAS-02 )
class Item extends Model {

    protected $table      = 'item';
    protected $primaryKey = 'item_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 대분류 → 허용 소분류 ( E_ITEM_KIND_MISMATCH 검증 기준 )
    //   구분 기준은 "단독으로 파는가" — 단독 판매 FINISHED / 사내 소비 SEMI / 미조립 세트 SET
    const KINDS_BY_TYPE = [
        'MATERIAL' => ['RAW', 'STOCK'],
        'PRODUCT'  => ['FINISHED', 'SEMI', 'SET'],
    ];

    // 단위 코드 그룹 ( system_code )
    const UNIT_CODE_GROUP = 'UNIT';

    protected $fillable = [
        'item_code',
        'item_name',
        'item_type',
        'item_kind',
        'spec_unit',
        'spec',
        'routing_idx',
        'serial_use',
        'pack_qty',
        'safety_stock',
        'order_cycle_days',
        'moq',
        'unit_round',
        'spec_dim',
        'min_usable_spec',
        'calc_code',
        'std_sale_price',
        'std_buy_price',
        'std_subcon_price',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'spec_unit'  => 'EA',
        'serial_use' => 'N',
        'spec_dim'   => 1,
        'is_use'     => 'Y',
    ];

    // decimal 은 문자열로 내려와 그리드/폼에서 '0.000' 으로 보이므로 float 로 변환
    protected function casts(): array {
        return [
            'pack_qty'         => 'float',
            'safety_stock'     => 'float',
            'order_cycle_days' => 'integer',
            'moq'              => 'float',
            'unit_round'       => 'float',
            'spec_dim'         => 'integer',
            'min_usable_spec'  => 'float',
            'cycle_time_sec'   => 'float',
            'std_sale_price'   => 'float',
            'std_buy_price'    => 'float',
            'std_subcon_price' => 'float',
        ];
    }

    // 등록자/수정자 자동 채움 ( Auth 없으면 0 = 시스템 )
    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function (self $m) {
            $m->update_account_idx = Auth::id();
        });
    }

    // 원소재( STOCK )만 소재 항목( 최소 사용 규격 · 계산식 )을 가진다
    public function isStock(): bool {
        return $this->item_kind === 'STOCK';
    }
}

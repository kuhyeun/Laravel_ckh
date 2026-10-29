<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 공정 ( BAS-04 )
class Process extends Model {

    protected $table      = 'process';
    protected $primaryKey = 'process_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const TYPES            = ['PRODUCTION', 'INSPECTION', 'EQUIPMENT', 'SUBCON'];
    const TYPE_INSPECTION  = 'INSPECTION';
    const TYPE_EQUIPMENT   = 'EQUIPMENT';   // 설비생산 : 사용 가능 설비 1개 이상 필수
    const INSPECTION_KINDS = ['NORMAL', 'IFL'];

    // 시스템 공정 system_key ( ProcessSeeder ) — 원자재출고 : 완료할 때 지시 자재가 다 출고됐는지 본다 / 포장 : 수량을 쌓는다
    const SYS_RM_OUT = 'RM_OUT';
    const SYS_PACK   = 'PACK';

    protected $fillable = [
        'process_code',
        'process_name',
        'process_type',
        'class_code',
        'inspection_kind',
        'is_system',
        'system_key',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_system' => 'N',
        'is_use'    => 'Y',
    ];

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

    // 시스템 기본 공정 여부 ( 표시용. 작업지시·실적에서 "완료만 처리" 판단 기준으로 사용 예정 )
    public function isSystem(): bool {
        return $this->is_system === 'Y';
    }

    // 수량 공정 : 실적 수량을 쌓는 공정 — 검사 · 시스템 공정이 아닌 공정 + 시스템 공정 중 포장
    public function isQtyProcess(): bool {
        if ($this->process_type === self::TYPE_INSPECTION) {
            return false;
        }
        return !$this->isSystem() || $this->system_key === self::SYS_PACK;
    }

    public function isRmOut(): bool {
        return $this->isSystem() && $this->system_key === self::SYS_RM_OUT;
    }

    public function equipments(): HasMany {
        return $this->hasMany(ProcessEquipment::class, 'process_idx', 'process_idx')
            ->orderBy('sort_order')->orderBy('process_equipment_idx');
    }
}

<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * 시스템 환경설정 ( group_key + key 단위 로우 )
 *
 * 복합 PK( system_config_group_key + system_config_key ) 테이블이라
 * "PK 는 단일 컬럼"이라는 Eloquent 기본 가정을 두 곳에서 풀어준다:
 *   1) $incrementing=false / $keyType='string' → 자동증가 정수 id 가정 해제
 *   2) setKeysForSaveQuery() 오버라이드 → save()/updateOrCreate()/delete() 가
 *      두 컬럼을 WHERE 조건으로 쓰도록 교정 ( 이게 없으면 한 컬럼만으로 갱신/삭제됨 )
 */
class SystemConfig extends Model {

    protected $table = 'system_config';

    // 복합키 : 단일 auto-increment PK 가정 해제
    public $incrementing = false;
    protected $keyType   = 'string';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'system_config_group_key',
        'system_config_key',
        'system_config_value',
        'system_config_remark',
        'system_config_etc',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'system_config_value' => '',
    ];

    // 등록자/수정자 자동 채움 ( Auth 없으면 0 = 시스템, 시더 등에서 안전 )
    protected static function booted(): void {
        static::creating(function (self $model) {
            if (empty($model->create_account_idx)) {
                $model->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function (self $model) {
            $model->update_account_idx = Auth::id();
        });
    }

    /**
     * 복합키 교정 : save()/delete() 가 두 컬럼으로 로우를 특정하도록 한다.
     * 키가 변경된 경우에도 "원래 로우"를 갱신하도록 original 값을 우선 사용.
     */
    protected function setKeysForSaveQuery($query) {
        return $query
            ->where('system_config_group_key', $this->keyValue('system_config_group_key'))
            ->where('system_config_key',       $this->keyValue('system_config_key'));
    }

    private function keyValue(string $column) {
        return $this->original[$column] ?? $this->getAttribute($column);
    }

    /** 단일 설정값 ( 없으면 $default ) */
    public static function get(string $group, string $key, $default = null) {
        $row = static::query()
            ->where('system_config_group_key', $group)
            ->where('system_config_key', $key)
            ->first();

        return $row ? $row->system_config_value : $default;
    }

    /** 그룹 전체 → [ key => value ] */
    public static function getGroup(string $group): array {
        return static::query()
            ->where('system_config_group_key', $group)
            ->pluck('system_config_value', 'system_config_key')
            ->toArray();
    }

    /**
     * 있으면 수정, 없으면 삽입.
     * @param array $extra remark/etc 등 추가로 넣을 컬럼
     */
    public static function put(string $group, string $key, mixed $value, array $extra = []): self {
        return static::updateOrCreate(
            [
                'system_config_group_key' => $group,
                'system_config_key'       => $key,
            ],
            array_merge(['system_config_value' => (string) $value], $extra)
        );
    }

    // ---- 스코프 ( 편의 ) ----
    public function scopeGroup(Builder $query, string $group): Builder {
        return $query->where('system_config_group_key', $group);
    }
}

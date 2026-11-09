<?php

namespace MesCore\Auth\Models;

use MesCore\Database\Factories\User\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable {
    use HasFactory;

    protected static function newFactory() {
        return UserFactory::new();
    }

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $table      = 'account_member';
    protected $primaryKey = 'account_idx';
    public $incrementing  = true;
    public $timestamps    = true;

    protected $fillable = [
        'user_id',
        'user_pw',
        'user_level',
        'is_use',
        'create_account_idx',
        'update_account_idx',
    ];

    // DB 조회 후 toArray() 또는 toJson()으로 변환시 자동으로 빠짐
    protected $hidden = [
        'user_pw',
    ];

    protected function casts(): array {
        return [
            'user_pw' => 'hashed',
            'create_datetime' => 'datetime',
            'update_datetime' => 'datetime',
        ];
    }

    // is_use : DB 는 enum( 'Y','N' ) — 화면 · API 는 true / false 로 주고받는다 ( 'boolean' 캐스트는 'N' 을 true 로 읽어 쓰지 않는다 )
    //   쿼리( where )는 그대로 'Y' / 'N' 으로 쓴다
    protected function isUse(): \Illuminate\Database\Eloquent\Casts\Attribute {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($v) => $v === 'Y',
            set: fn ($v) => ($v === 'Y' || $v === true || $v === 1 || $v === '1') ? 'Y' : 'N',
        );
    }

    // 저장 시 권한 레벨로 user_type 자동 결정 ( 0,1 → admin / 그 외 → user )
    // 모든 저장 경로( 등록·수정·회원가입·시더 )에서 일관되게 적용됨
    protected static function booted(): void {
        static::saving(function (self $user) {
            $user->user_type = in_array((int) $user->user_level, [0, 1], true) ? 'admin' : 'user';
        });
    }

    // 사람 고르기 목록( 작업자 · 검사자 · 담당자 … )에 나오는 계정 — 사용 중 + 시스템 계정( 레벨 0 ) 제외 ( 11-05 — 어느 메뉴에서든 )
    public const SYSTEM_LEVEL = 0;

    public function scopePickable($query) {
        return $query->where('is_use', 'Y')->where('user_level', '!=', self::SYSTEM_LEVEL);
    }

    // 고르기 기본값용 "나" — 시스템 계정이면 null ( 목록에 없으니 기본값도 비운다 )
    public static function pickableMe(): ?int {
        $u = \Illuminate\Support\Facades\Auth::user();
        return $u && (int) $u->user_level !== self::SYSTEM_LEVEL ? (int) $u->account_idx : null;
    }

    // 저장 검사 : 고를 수 있는 계정만 ( exists 대신 )
    public static function pickableRule(): \Illuminate\Validation\Rules\Exists {
        return \Illuminate\Validation\Rule::exists('account_member', 'account_idx')->where('is_use', 'Y')->whereNot('user_level', self::SYSTEM_LEVEL);
    }

    public function username(): string {
        return 'user_id';
    }

    public function getAuthPassword(): string {
        return $this->user_pw;
    }

    // 캐시 키에 DB 이름을 넣는다 ( 공용 캐시에서 업체끼리 섞이지 않도록 )
    public function getMenuCacheKey(): string {
        return 'mes:menu:' . $this->getConnection()->getDatabaseName() . ":{$this->account_idx}";
    }
}

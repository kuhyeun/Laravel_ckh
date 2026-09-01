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
            'is_use' => 'boolean',
            'create_datetime' => 'datetime',
            'update_datetime' => 'datetime',
        ];
    }

    public function username(): string {
        return 'user_id';
    }

    public function getAuthPassword(): string {
        return $this->user_pw;
    }

    public function getMenuCacheKey(): string {
        return "user_menu_for_user_{$this->account_idx}";
    }
}

<?php

namespace MesCore\Basic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;

// 첨부파일 ( CMP-03 ) — ref_type + ref_idx 로 어떤 대상에도 붙는다. 저장 · 삭제는 AttachmentService
class Attachment extends Model {

    protected $table      = 'attachment';
    protected $primaryKey = 'attachment_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 구분 코드 그룹 ( 코드관리 )
    const CATEGORY_GROUP = 'ATTACH_TYPE';

    protected $fillable = [
        'ref_type',
        'ref_idx',
        'category_code',
        'file_name',
        'file_ext',
        'file_size',
        'mime_type',
        'storage_disk',
        'storage_path',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    // 저장 위치는 화면에 내보내지 않는다
    protected $hidden = [
        'storage_disk',
        'storage_path',
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

    public function creator() {
        return $this->belongsTo(User::class, 'create_account_idx', 'account_idx');
    }
}

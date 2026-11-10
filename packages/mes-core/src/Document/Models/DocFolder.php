<?php

namespace MesCore\Document\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 문서관리 폴더 ( 11004 ) — 자기참조 계층 · 파일은 attachment( ref_type = doc_folder )
class DocFolder extends Model {

    // 첨부 대상 이름 ( AttachmentService 등록 · attachment.ref_type )
    public const REF_TYPE = 'doc_folder';

    protected $table      = 'doc_folder';
    protected $primaryKey = 'folder_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'parent_folder_idx',
        'folder_name',
        'create_account_idx',
        'update_account_idx',
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

    public function parent(): BelongsTo {
        return $this->belongsTo(self::class, 'parent_folder_idx', 'folder_idx');
    }

    public function children(): HasMany {
        return $this->hasMany(self::class, 'parent_folder_idx', 'folder_idx');
    }
}

<?php

namespace MesCore\Support;

use Illuminate\Support\Facades\Auth;

// 등록자 · 수정자 자동 기록 ( create_account_idx · update_account_idx ) — DB 규약의 감사 칸
//   모델에 const CREATED_AT = 'create_datetime' / UPDATED_AT = 'update_datetime' 를 함께 둔다
//   update_account_idx 칸이 없는 표( 원장 · 불량 )는 $recordsUpdater = false
trait RecordsAccount {

    protected static function bootRecordsAccount(): void {
        static::creating(function ($m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function ($m) {
            if ($m->recordsUpdater ?? true) {
                $m->update_account_idx = Auth::id();
            }
        });
    }
}

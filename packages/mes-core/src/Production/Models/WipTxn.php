<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;

// 재공 이력 ( wip_txn ) — 쓰기는 WipService( DB::table ) · 조회 목록용 모델
class WipTxn extends Model {
    protected $table      = 'wip_txn';
    protected $primaryKey = 'wip_txn_idx';
    public $timestamps    = false;
    protected $guarded    = ['*'];
}

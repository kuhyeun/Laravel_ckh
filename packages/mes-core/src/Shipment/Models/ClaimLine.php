<?php

namespace MesCore\Shipment\Models;

use Illuminate\Database\Eloquent\Model;

// 클레임 품목 줄 — 첨부 대상( claim_line ) 확인용 모델 · 쓰기는 ClaimService
class ClaimLine extends Model {
    protected $table      = 'claim_line';
    protected $primaryKey = 'claim_line_idx';
    public $timestamps    = false;
    protected $guarded    = ['*'];
}

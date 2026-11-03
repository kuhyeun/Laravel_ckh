<?php

namespace MesCore\Label\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Stock\Models\Lot;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Support\RecordsAccount;

// 바코드 라벨 발행 — 대상 하나( LOT · 출하 줄 ) × 장수. 번호는 UNIQUE 아님 ( 같은 번호 재발행 )
//   지우지 않는다 ( 번호가 이미 밖에 붙어 있다 ) — 폐기는 status = SCRAPPED + 사유
class BarcodeLabel extends Model {
    use RecordsAccount;

    protected $table      = 'barcode_label';
    protected $primaryKey = 'label_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const TARGET_LOT      = 'LOT';
    const TARGET_SHIPMENT = 'SHIPMENT_LINE';

    protected $fillable = [
        'target', 'lot_idx', 'shipment_line_idx', 'barcode_no', 'label_template_idx', 'label_count', 'label_qty',
        'status', 'scrap_reason', 'scrapped_by', 'scrapped_at', 'print_total', 'last_printed_at',
    ];

    protected function casts(): array {
        return [
            'label_qty'       => 'decimal:6',
            'scrapped_at'     => 'datetime',
            'last_printed_at' => 'datetime',
        ];
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function shipmentLine() {
        return $this->belongsTo(ShipmentLine::class, 'shipment_line_idx', 'shipment_line_idx');
    }

    public function template() {
        return $this->belongsTo(LabelTemplate::class, 'label_template_idx', 'label_template_idx');
    }
}

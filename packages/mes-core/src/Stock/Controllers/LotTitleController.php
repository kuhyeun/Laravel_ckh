<?php

namespace MesCore\Stock\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Services\LotService;

// LOT 표시 번호 ( 11-24 · docs/LOT표시번호-설계.md ) — 재고관리 · 제품재고 LOT 창 · 물품표 미리보기가 같이 쓴다
//   권한 : 보기 = 아래 화면 중 하나 읽기 / 바꾸기 = 자재 LOT → 재고관리 · 물품표 쓰기 · 제품 LOT → 제품재고 · 현품표 쓰기
class LotTitleController extends Controller {
    private const READ  = ['Stock/StockStatus', 'Product/ProductStock', 'Purchase/MaterialTag', 'Shipment/ShipmentTag'];
    private const WRITE = ['MATERIAL' => ['Stock/StockStatus', 'Purchase/MaterialTag'], 'PRODUCT' => ['Product/ProductStock', 'Shipment/ShipmentTag']];

    public function __construct(private MenuPermissionService $perm, private LotService $lots) {}

    private function canEdit(Lot $lot): bool {
        $type = $lot->item?->item_type === 'PRODUCT' ? 'PRODUCT' : 'MATERIAL';
        return $this->perm->canPageForMe(self::WRITE[$type], 'can_write');
    }

    private function find(int $lotIdx): Lot {
        abort_unless($this->perm->canPageForMe(self::READ, 'can_read'), 403, 'Permission Denied.');
        $lot = Lot::with('item:item_idx,item_type')->find($lotIdx);
        abort_unless($lot, 404, 'LOT 을 찾을 수 없습니다.');
        return $lot;
    }

    private function out(Lot $lot): array {
        return [
            'lot_idx'     => (int) $lot->lot_idx,
            'lot_no'      => $lot->lot_no,          // 표시 번호
            'original_no' => $lot->original_no,     // 원번호
            'lot_title'   => $lot->lot_title,       // 바꾼 번호 ( 없으면 null )
            'can_edit'    => $this->canEdit($lot),
        ];
    }

    public function show(int $lotIdx) {
        return response()->json(['lot' => $this->out($this->find($lotIdx))]);
    }

    public function save(Request $request) {
        $v = $request->validate(['lot_idx' => 'required|integer', 'lot_title' => 'nullable|string|max:60'], [], ['lot_title' => 'LOT 번호']);
        $lot = $this->find((int) $v['lot_idx']);
        abort_unless($this->canEdit($lot), 403, 'Permission Denied.');
        $lot = $this->lots->rename((int) $v['lot_idx'], $v['lot_title'] ?? null);
        $lot->load('item:item_idx,item_type');
        return response()->json(['message' => 'Success', 'lot' => $this->out($lot)]);
    }
}

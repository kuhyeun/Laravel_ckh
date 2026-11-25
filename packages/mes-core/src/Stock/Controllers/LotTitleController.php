<?php

namespace MesCore\Stock\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Services\LotService;

// LOT 표시 번호 ( 11-24 · docs/LOT표시번호-설계.md ) — 재고관리 · 제품재고 LOT 창 · 물품표 미리보기가 같이 쓴다
//   권한 : 보기 = 아래 화면 중 하나 읽기 ( LOT 관리 포함 — 11-25 ) / 바꾸기 = 자재 LOT → 재고관리 · 물품표 쓰기 · 제품 LOT → 제품재고 · 현품표 쓰기
//   history ( 11-25 · MES_1125 #14 ) : LOT 이력 창 — 라벨 인쇄 이력 + LOT 번호 변경 이력
class LotTitleController extends Controller {
    private const READ  = ['Stock/StockStatus', 'Product/ProductStock', 'Purchase/MaterialTag', 'Shipment/ShipmentTag', 'Shipment/LotTrace'];
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

    // LOT 이력 — 인쇄 이력( 이 LOT 의 모든 라벨 · 새로 발행 전 바코드 · 폐기 포함 ) · 번호 변경 이력 · 최근 순
    public function history(int $lotIdx) {
        $lot = $this->find($lotIdx);
        $lot->load('item:item_idx,item_type,item_code,item_name,spec');
        $who = fn (string $a) => DB::raw("COALESCE(NULLIF({$a}.user_name, ''), {$a}.user_id) as user_name");
        $prints = DB::table('label_print_log as p')
            ->join('barcode_label as b', 'b.label_idx', '=', 'p.label_idx')
            ->leftJoin('label_template as t', 't.label_template_idx', '=', 'b.label_template_idx')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'p.printed_by')
            ->where('b.lot_idx', $lotIdx)
            ->orderByDesc('p.printed_at')->orderByDesc('p.label_print_log_idx')
            ->get(['p.printed_at', 'p.print_count', 'b.barcode_no', 'b.status', 't.template_name', $who('u')]);
        $renames = DB::table('lot_title_log as g')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'g.changed_by')
            ->where('g.lot_idx', $lotIdx)
            ->orderByDesc('g.changed_at')->orderByDesc('g.lot_title_log_idx')
            ->get(['g.changed_at', 'g.before_no', 'g.after_no', $who('u')]);
        $labels = DB::table('barcode_label')->where('lot_idx', $lotIdx)
            ->selectRaw("COUNT(*) as n, SUM(status = 'ISSUED') as issued")->first();
        return response()->json([
            'lot' => $this->out($lot) + [
                'item_code' => $lot->item?->item_code, 'item_name' => $lot->item?->item_name, 'item_spec' => $lot->item?->spec,
                'kind'      => $lot->item?->item_type === 'PRODUCT' || $lot->source === 'PRODUCTION' ? 'PRODUCT' : 'MATERIAL',
                'label_n'   => (int) ($labels->n ?? 0), 'issued_n' => (int) ($labels->issued ?? 0),
            ],
            'prints'  => $prints->map(fn ($r) => [
                'printed_at' => substr((string) $r->printed_at, 0, 16), 'print_count' => (int) $r->print_count, 'barcode_no' => $r->barcode_no,
                'status' => $r->status, 'template_name' => $r->template_name, 'user_name' => $r->user_name,
            ])->values(),
            'renames' => $renames->map(fn ($r) => [
                'changed_at' => substr((string) $r->changed_at, 0, 16), 'before_no' => $r->before_no, 'after_no' => $r->after_no, 'user_name' => $r->user_name,
            ])->values(),
        ]);
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

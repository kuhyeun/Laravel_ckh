<?php

namespace MesCore\Stock\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Stock\Concerns\ScopesItemType;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemCode;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\StockAdjustService;
use MesCore\Stock\Services\StockMoveService;
use MesCore\Stock\Services\StockMiscOutService;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Services\OpResultService;
use MesCore\Shipment\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// 창고이동 ( INV-04 ) · 재고조정 ( INV-05 ) · 기타출고 — docs/screens/40-구매자재.md §17 ~ §20
//   처리는 재고현황 팝업 · 각 메뉴 어디서든 같은 API. 목록은 이력( 한 행 = 전표 하나 = 줄 하나 ) + 취소
//   권한은 라우트( 창고이동 · 재고조정 메뉴 )가 본다
//   제품관리( /product )도 같은 API — 품목 구분은 라우트 범위( ItemScope ). 생산 입고 · 출하 이력은 제품관리에서 조회만
class StockProcessController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;
    use ScopesItemType;

    public function __construct(private StockMoveService $moves, private StockAdjustService $adjusts, private StockMiscOutService $miscs) {}

    // ---- 창고이동 ----

    public function moveList(Request $request) {
        return $this->history($request, StockMoveService::TXN_TYPE, 'moves');
    }

    public function move(Request $request) {
        $data = $request->validate([
            'stock_balance_idx' => 'required|integer',
            'to_warehouse_idx'  => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'occurred_at'       => 'required|date_format:Y-m-d\TH:i',
            'remark'            => 'nullable|string|max:200',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.'], [
            'to_warehouse_idx' => '받는 창고', 'qty' => '수량', 'occurred_at' => '이동일시', 'remark' => '비고',
        ]);

        return $this->run(fn () => $this->moves->move($data), '창고이동');
    }

    public function moveCancel(Request $request, int $txnIdx) {
        $this->moves->cancel($this->findTxn($request, $txnIdx, StockMoveService::TXN_TYPE));
        return response()->json(['message' => 'Success']);
    }

    // ---- 재고조정 ----

    public function adjustList(Request $request) {
        return $this->history($request, StockAdjustService::TXN_TYPE, 'adjusts');
    }

    public function reasons() {
        return response()->json([
            'reasons' => SystemCode::where('system_code_group_key', StockAdjustService::REASON_GROUP)->where('is_use', 'Y')
                ->orderBy('sort_order')->get(['system_code_key as value', 'system_code_name as label']),
        ]);
    }

    public function adjust(Request $request) {
        $data = $request->validate([
            'stock_balance_idx' => 'required|integer',
            'after_qty'         => 'required|numeric|min:0|max:999999999999',
            'reason_code'       => 'required|string|max:30',
            'remark'            => 'nullable|string|max:200',
            'occurred_at'       => 'required|date_format:Y-m-d\TH:i',
        ], [], [
            'after_qty' => '조정 후 수량', 'reason_code' => '사유', 'remark' => '상세', 'occurred_at' => '조정일시',
        ]);
        $this->assertBalanceInScope($request, (int) $data['stock_balance_idx']);

        return $this->run(fn () => $this->adjusts->adjust($data), '재고조정');
    }

    public function adjustCancel(Request $request, int $txnIdx) {
        $this->adjusts->cancel($this->findTxn($request, $txnIdx, StockAdjustService::TXN_TYPE));
        return response()->json(['message' => 'Success']);
    }

    // ---- 기타출고 ( 권한 = 출고관리 ) ----

    public function miscList(Request $request) {
        return $this->history($request, StockMiscOutService::TXN_TYPE, 'miscs');
    }

    public function miscOut(Request $request) {
        $data = $request->validate([
            'stock_balance_idx' => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'occurred_at'       => 'required|date_format:Y-m-d\TH:i',
            'remark'            => 'required|string|max:200',
            'work_order_idx'    => 'nullable|integer|exists:work_order,work_order_idx',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.', 'remark.required' => '메모( 출고 사유 )를 넣으세요.'], [
            'qty' => '수량', 'occurred_at' => '출고일시', 'remark' => '메모', 'work_order_idx' => '관련 작업지시',
        ]);
        $this->assertBalanceInScope($request, (int) $data['stock_balance_idx']);

        return $this->run(fn () => $this->miscs->out($data), '기타출고');
    }

    public function miscCancel(Request $request, int $txnIdx) {
        $this->miscs->cancel($this->findTxn($request, $txnIdx, StockMiscOutService::TXN_TYPE));
        return response()->json(['message' => 'Success']);
    }

    // ---- 제품관리 조회 ( 처리 · 취소는 공정실적 · 출하관리 ) ----

    public function productionList(Request $request) {
        return $this->history($request, OpResultService::TXN_TYPE, 'receipts');
    }

    public function shipmentList(Request $request) {
        return $this->history($request, ShipmentService::TXN_TYPE, 'shipments');
    }

    // 이 품목을 자재로 쓰는 작업지시 ( 최근 순 ) — open = 대기 · 진행만 ( 작업지시 출고 ) / 아니면 완료까지 ( 기타출고의 관련 지시 )
    //   제품 범위 : 이 제품을 만든 작업지시 ( 제품 기타출고의 관련 지시 )
    public function workOrders(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'open' => 'nullable|boolean']);
        $statuses = !empty($v['open']) ? ['WAIT', 'RUN'] : ['WAIT', 'RUN', 'DONE'];
        $this->assertItemInScope($request, (int) $v['item_idx']);

        $rows = WorkOrder::with('item:item_idx,item_code,item_name,spec,material')
            ->whereIn('status', $statuses)
            ->when($this->itemScope($request) === 'PRODUCT',
                fn ($q) => $q->where('item_idx', $v['item_idx']),
                fn ($q) => $q->whereHas('materials', fn ($r) => $r->where('child_item_idx', $v['item_idx'])))
            ->orderByDesc('work_order_idx')->limit(100)
            ->get(['work_order_idx', 'wo_no', 'item_idx', 'status', 'target_qty', 'start_date']);

        return response()->json(['work_orders' => $rows->map(fn (WorkOrder $w) => [
            'value'  => $w->work_order_idx,
            'label'  => "{$w->wo_no} · " . trim(($w->item?->item_code ?? '') . ' ' . ($w->item?->item_name ?? '')) . ' · ' . (WorkOrder::STATUSES[$w->status] ?? $w->status),
            'status' => $w->status,
        ])->values()]);
    }

    // ---- 내부 ----

    private function run(callable $fn, string $what) {
        try {
            [$txn, $warnings] = $fn();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("{$what} 실패 : " . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'txn_no' => $txn->txn_no]);
    }

    private function findTxn(Request $request, int $idx, string $type): StockTxn {
        $txn = StockTxn::where('txn_type', $type)->find($idx);
        if (!$txn) {
            abort(404);
        }
        $this->assertTxnInScope($request, $txn);
        return $txn;
    }

    // 이력 : 기간( 발생일 ) + 검색어( 번호 · 품번 · 품명 · LOT ) + 상태. 기본 정렬 등록 시각 최근 먼저
    private function history(Request $request, string $type, string $legacyKey) {
        $v = $request->validate([
            'from'    => 'nullable|date_format:Y-m-d',
            'to'      => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword' => 'nullable|string|max:100',
            'status'  => 'nullable|in:DONE,CANCELED',
        ]);

        $query = StockTxnLine::query()
            ->select('stock_txn_line.*', 'stock_txn.txn_no', 'stock_txn.occurred_at', 'stock_txn.recorded_at', 'stock_txn.status as txn_status', 'stock_txn.create_account_idx as txn_creator')
            ->join('stock_txn', 'stock_txn.stock_txn_idx', '=', 'stock_txn_line.stock_txn_idx')
            ->where('stock_txn.txn_type', $type)
            ->when($this->itemScope($request), fn ($q, $scope) => $q->whereHas('item', fn ($r) => $r->where('item_type', $scope)))
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot', 'warehouse:warehouse_idx,warehouse_name', 'toWarehouse:warehouse_idx,warehouse_name', 'workOrder:work_order_idx,wo_no'])
            ->orderByDesc('stock_txn.recorded_at')->orderByDesc('stock_txn.stock_txn_idx');

        if (!empty($v['from']))   $query->where('stock_txn.occurred_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))     $query->where('stock_txn.occurred_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['status'])) $query->where('stock_txn.status', $v['status']);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'txn_no'          => fn ($q, $kw) => $q->where('stock_txn.txn_no', 'like', $kw),
            'wo_no'           => fn ($q, $kw) => $q->whereHas('workOrder', fn ($r) => $r->where('wo_no', 'like', $kw)),
            'item_code'       => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name'       => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'lot_no'          => fn ($q, $kw) => $q->whereHas('lot', fn ($r) => $r->where('lot_no', 'like', $kw)),
            'external_lot_no' => fn ($q, $kw) => $q->whereHas('lot', fn ($r) => $r->where('external_lot_no', 'like', $kw)),
        ]);

        $reasons = SystemCode::where('system_code_group_key', StockAdjustService::REASON_GROUP)->pluck('system_code_name', 'system_code_key');
        $users   = User::pluck('user_name', 'account_idx');

        // 줄 → 수주번호 · 출하번호 · 고객사 ( 생산 입고 · 출하만 — 페이지 행만 한 번에 )
        //   map 은 화살표 함수라 값을 복사해 잡는다 → 나중에 채우는 표는 객체로 넘긴다
        $refs    = new \ArrayObject();
        $prepare = function (Collection $rows) use ($type, $refs) {
            if ($type === OpResultService::TXN_TYPE) $refs->exchangeArray($this->productionRefs($rows));
            if ($type === ShipmentService::TXN_TYPE) $refs->exchangeArray($this->shipmentRefs($rows));
        };

        return $this->listResponse($request, $query, ['txn_no', 'occurred_at', 'recorded_at'], $legacyKey, fn (StockTxnLine $l) => [
            'stock_txn_idx'   => $l->stock_txn_idx,
            'txn_no'          => $l->txn_no,
            'occurred_at'     => substr((string) $l->occurred_at, 0, 16),
            'recorded_at'     => substr((string) $l->recorded_at, 0, 16),
            'status'          => $l->txn_status,
            'item_code'       => $l->item?->item_code,
            'item_name'       => $l->item?->item_name,
            'item_spec'       => $l->item?->spec,
            'item_material'   => $l->item?->material,
            'spec_unit'       => $l->item?->spec_unit,
            'lot_no'          => $l->lot?->lot_no,
            'spec_value'      => $l->lot?->spec_value !== null ? (float) $l->lot->spec_value : null,
            'warehouse_name'  => $l->warehouse?->warehouse_name,
            'to_warehouse'    => $l->toWarehouse?->warehouse_name,
            'qty'             => (float) $l->qty,
            'book_qty'        => $l->book_qty !== null ? (float) $l->book_qty : null,
            'delta'           => (float) $l->pass_qty,
            'reason'          => $l->reason_code ? ($reasons[$l->reason_code] ?? $l->reason_code) : null,
            'remark'          => $l->remark,
            'work_order_idx'  => $l->work_order_idx,
            'wo_no'           => $l->workOrder?->wo_no,
            'order_no'        => $refs[$l->stock_txn_line_idx]['order_no'] ?? null,
            'shipment_no'     => $refs[$l->stock_txn_line_idx]['shipment_no'] ?? null,
            'partner_name'    => $refs[$l->stock_txn_line_idx]['partner_name'] ?? null,
            'user_name'       => $users[$l->txn_creator] ?? '',
        ], $prepare);
    }

    // 생산 입고 줄 → 그 작업지시가 담은 수주번호 ( 여럿이면 · 로 )
    private function productionRefs(Collection $lines): array {
        $woIdxs = $lines->pluck('work_order_idx')->filter()->unique()->values();
        if ($woIdxs->isEmpty()) return [];
        $byWo = DB::table('work_order_source as ws')
            ->join('sales_order_line as sl', 'sl.sales_order_line_idx', '=', 'ws.sales_order_line_idx')
            ->join('sales_order as so', 'so.sales_order_idx', '=', 'sl.sales_order_idx')
            ->whereIn('ws.work_order_idx', $woIdxs)
            ->get(['ws.work_order_idx', 'so.order_no'])
            ->groupBy('work_order_idx')
            ->map(fn ($g) => $g->pluck('order_no')->unique()->sort()->implode(' · '));

        return $lines->mapWithKeys(fn (StockTxnLine $l) => [$l->stock_txn_line_idx => ['order_no' => $byWo[$l->work_order_idx] ?? null]])->all();
    }

    // 출하 줄 → 출하번호 · 고객사 · 수주번호
    private function shipmentRefs(Collection $lines): array {
        $idxs = $lines->pluck('shipment_line_idx')->filter()->unique()->values();
        if ($idxs->isEmpty()) return [];
        $byLine = DB::table('shipment_line as sl')
            ->join('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 's.partner_idx')
            ->leftJoin('sales_order_line as ol', 'ol.sales_order_line_idx', '=', 'sl.sales_order_line_idx')
            ->leftJoin('sales_order as so', 'so.sales_order_idx', '=', 'ol.sales_order_idx')
            ->whereIn('sl.shipment_line_idx', $idxs)
            ->get(['sl.shipment_line_idx', 's.shipment_no', 'p.partner_name', 'so.order_no'])
            ->keyBy('shipment_line_idx');

        return $lines->mapWithKeys(function (StockTxnLine $l) use ($byLine) {
            $r = $byLine[$l->shipment_line_idx] ?? null;
            return [$l->stock_txn_line_idx => ['shipment_no' => $r?->shipment_no, 'partner_name' => $r?->partner_name, 'order_no' => $r?->order_no]];
        })->all();
    }
}

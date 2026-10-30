<?php

namespace MesCore\Stock\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemCode;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\StockAdjustService;
use MesCore\Stock\Services\StockMoveService;
use MesCore\Stock\Services\StockMiscOutService;
use MesCore\Production\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// 창고이동 ( INV-04 ) · 재고조정 ( INV-05 ) · 기타출고 — docs/screens/40-구매자재.md §17 ~ §20
//   처리는 재고현황 팝업 · 각 메뉴 어디서든 같은 API. 목록은 이력( 한 행 = 전표 하나 = 줄 하나 ) + 취소
//   권한은 라우트( 창고이동 · 재고조정 메뉴 )가 본다
class StockProcessController extends Controller {
    use RespondsWithList;

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

    public function moveCancel(int $txnIdx) {
        $this->moves->cancel($this->findTxn($txnIdx, StockMoveService::TXN_TYPE));
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

        return $this->run(fn () => $this->adjusts->adjust($data), '재고조정');
    }

    public function adjustCancel(int $txnIdx) {
        $this->adjusts->cancel($this->findTxn($txnIdx, StockAdjustService::TXN_TYPE));
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

        return $this->run(fn () => $this->miscs->out($data), '기타출고');
    }

    public function miscCancel(int $txnIdx) {
        $this->miscs->cancel($this->findTxn($txnIdx, StockMiscOutService::TXN_TYPE));
        return response()->json(['message' => 'Success']);
    }

    // 이 품목을 자재로 쓰는 작업지시 ( 최근 순 ) — open = 대기 · 진행만 ( 작업지시 출고 ) / 아니면 완료까지 ( 기타출고의 관련 지시 )
    public function workOrders(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'open' => 'nullable|boolean']);
        $statuses = !empty($v['open']) ? ['WAIT', 'RUN'] : ['WAIT', 'RUN', 'DONE'];

        $rows = WorkOrder::with('item:item_idx,item_code,item_name,spec,material')
            ->whereIn('status', $statuses)
            ->whereHas('materials', fn ($q) => $q->where('child_item_idx', $v['item_idx']))
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

    private function findTxn(int $idx, string $type): StockTxn {
        $txn = StockTxn::where('txn_type', $type)->find($idx);
        if (!$txn) {
            abort(404);
        }
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
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot', 'warehouse:warehouse_idx,warehouse_name', 'toWarehouse:warehouse_idx,warehouse_name', 'workOrder:work_order_idx,wo_no'])
            ->orderByDesc('stock_txn.recorded_at')->orderByDesc('stock_txn.stock_txn_idx');

        if (!empty($v['from']))   $query->where('stock_txn.occurred_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))     $query->where('stock_txn.occurred_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['status'])) $query->where('stock_txn.status', $v['status']);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function ($w) use ($kw) {
                $w->where('stock_txn.txn_no', 'like', $kw)
                  ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw)->orWhere('external_lot_no', 'like', $kw));
            });
        }

        $reasons = SystemCode::where('system_code_group_key', StockAdjustService::REASON_GROUP)->pluck('system_code_name', 'system_code_key');
        $users   = User::pluck('user_name', 'account_idx');

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
            'wo_no'           => $l->workOrder?->wo_no,
            'user_name'       => $users[$l->txn_creator] ?? '',
        ]);
    }
}

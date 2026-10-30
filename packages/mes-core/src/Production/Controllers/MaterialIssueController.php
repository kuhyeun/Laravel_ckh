<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Warehouse;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Stock\Models\StockTxnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// 자재출고 ( INV-03 · 자재관리 > 출고관리 ) — docs/screens/50-생산.md §10 ~ §17
//   왼쪽 작업지시( 대기 · 진행 · [완료 포함] ) → 오른쪽 자재 줄마다 계산 소요 · LOT 채움 → 출고 / 아래 출고 이력 → 줄 취소
//   반납 ( §15-1 ) : 출고한 LOT 그대로 창고로 — 완료 · 취소된 줄도. 반납 이력 → 반납 취소
class MaterialIssueController extends Controller {
    use RespondsWithList;

    private const SORTABLE = ['wo_no', 'start_date', 'due_date'];

    public function __construct(private MaterialIssueService $issues) {}

    // 작업지시 목록 : 착수 예정 기간 + 검색어( 지시번호 · 품번 · 품명 · LOT ) + 출고 상태( NONE 미출고 / SOME 출고 있음 )
    public function list(Request $request) {
        $v = $request->validate([
            'from'         => 'nullable|date_format:Y-m-d',
            'to'           => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'      => 'nullable|string|max:100',
            'issue_status' => 'nullable|in:NONE,SOME',
            'include_done' => 'nullable|boolean',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = WorkOrder::query()
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot:lot_idx,lot_no'])
            ->withCount(['materials', 'materials as issued_count' => fn ($q) => $q->where('issued_qty', '>', 0)])
            ->whereIn('status', filter_var($v['include_done'] ?? false, FILTER_VALIDATE_BOOLEAN) ? ['WAIT', 'RUN', 'DONE'] : ['WAIT', 'RUN'])   // 완료 포함 = 남은 자재 반납
            ->orderBy('start_date')->orderBy('work_order_idx');

        if (!empty($v['from'])) $query->where('start_date', '>=', $v['from']);
        if (!empty($v['to']))   $query->where('start_date', '<=', $v['to']);
        $has = fn ($q) => $q->where('issued_qty', '>', 0);
        if (($v['issue_status'] ?? '') === 'NONE') $query->whereDoesntHave('materials', $has);
        if (($v['issue_status'] ?? '') === 'SOME') $query->whereHas('materials', $has);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('wo_no', 'like', $kw)
                  ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'orders', fn (WorkOrder $w) => [
            'work_order_idx'  => $w->work_order_idx,
            'wo_no'           => $w->wo_no,
            'item_code'       => $w->item?->item_code,
            'item_name'       => $w->item?->item_name,
            'item_spec'       => $w->item?->spec,
            'item_material'   => $w->item?->material,
            'spec_unit'       => $w->item?->spec_unit,
            'target_qty'      => (float) $w->target_qty,
            'lot_no'          => $w->lot?->lot_no,
            'start_date'      => $w->start_date?->toDateString(),
            'due_date'        => $w->due_date?->toDateString(),
            'status'          => $w->status,
            'materials_count' => $w->materials_count,
            'issued_count'    => $w->issued_count,
        ]);
    }

    // 지시 하나의 출고 계획 ( 자재 줄 · 계산 과정 · 후보 LOT · 기본 채움 )
    public function plan(int $woIdx) {
        $wo = $this->find($woIdx);
        return response()->json([
            'order' => [
                'work_order_idx' => $wo->work_order_idx,
                'wo_no'          => $wo->wo_no,
                'status'         => $wo->status,
                'item_code'      => $wo->item?->item_code,
                'item_name'      => $wo->item?->item_name,
                'item_spec'      => $wo->item?->spec,
                'item_material'  => $wo->item?->material,
                'spec_unit'      => $wo->item?->spec_unit,
                'target_qty'     => (float) $wo->target_qty,
                'lot_no'         => $wo->lot?->lot_no,
                'start_date'     => $wo->start_date?->toDateString(),
            ],
            'materials' => $this->issues->plan($wo),
            'now'       => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // 지시의 출고 이력 ( 줄 단위 · 최근 먼저 )
    public function history(int $woIdx) {
        $wo    = $this->find($woIdx);
        $users = User::pluck('user_name', 'account_idx');
        $rows  = StockTxnLine::with(['txn', 'item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot', 'warehouse:warehouse_idx,warehouse_name'])
            ->where('work_order_idx', $wo->work_order_idx)
            ->whereHas('txn', fn ($q) => $q->where('txn_type', MaterialIssueService::TXN_TYPE))
            ->orderByDesc('stock_txn_line_idx')->get()
            ->map(fn (StockTxnLine $l) => [
                'stock_txn_line_idx' => $l->stock_txn_line_idx,
                'txn_no'             => $l->txn?->txn_no,
                'occurred_at'        => substr((string) $l->txn?->occurred_at, 0, 16),
                'item_code'          => $l->item?->item_code,
                'item_name'          => $l->item?->item_name,
                'item_spec'          => $l->item?->spec,
                'item_material'      => $l->item?->material,
                'spec_unit'          => $l->item?->spec_unit,
                'lot_no'             => $l->lot?->lot_no,
                'spec_value'         => $l->lot?->spec_value !== null ? (float) $l->lot->spec_value : null,
                'warehouse_name'     => $l->warehouse?->warehouse_name,
                'qty'                => (float) $l->qty,
                'consumed_qty'       => (float) $l->consumed_qty,
                'status'             => $l->line_status,
                'remark'             => $l->txn?->remark,
                'user_name'          => $users[$l->txn?->create_account_idx] ?? '',
            ]);
        return response()->json(['rows' => $rows]);
    }

    public function save(Request $request) {
        $data = $request->validate([
            'work_order_idx'                  => 'required|integer',
            'occurred_at'                     => 'required|date_format:Y-m-d\TH:i',
            'remark'                          => 'nullable|string|max:200',
            'lines'                           => 'required|array|min:1|max:500',
            'lines.*.work_order_material_idx' => 'required|integer',
            'lines.*.stock_balance_idx'       => 'required|integer',
            'lines.*.qty'                     => 'required|numeric|min:0|max:999999999999',
        ], [], ['occurred_at' => '출고일시', 'remark' => '비고', 'lines' => '출고 수량', 'lines.*.qty' => '출고 수량']);

        $wo = $this->find($data['work_order_idx']);
        try {
            [$txn, $warnings] = $this->issues->issue($wo, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('자재출고 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'txn_no' => $txn->txn_no]);
    }

    public function cancel(Request $request) {
        $data = $request->validate([
            'work_order_idx'        => 'required|integer',
            'stock_txn_line_idxs'   => 'required|array|min:1|max:200',
            'stock_txn_line_idxs.*' => 'integer',
        ]);
        $n = $this->issues->cancel($this->find($data['work_order_idx']), $data['stock_txn_line_idxs']);
        return response()->json(['message' => 'Success', 'canceled' => $n]);
    }

    // ---- 반납 ----

    // 반납할 수 있는 LOT · 반납 이력 · 창고 선택지
    public function returnPlan(int $woIdx) {
        $wo    = $this->find($woIdx);
        $users = User::pluck('user_name', 'account_idx');
        $history = StockTxnLine::with(['txn', 'item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot', 'warehouse:warehouse_idx,warehouse_name'])
            ->where('work_order_idx', $wo->work_order_idx)
            ->whereHas('txn', fn ($q) => $q->where('txn_type', MaterialIssueService::RETURN_TYPE))
            ->orderByDesc('stock_txn_line_idx')->get()
            ->map(fn (StockTxnLine $l) => [
                'stock_txn_line_idx' => $l->stock_txn_line_idx,
                'txn_no'             => $l->txn?->txn_no,
                'occurred_at'        => substr((string) $l->txn?->occurred_at, 0, 16),
                'item_code'          => $l->item?->item_code,
                'item_name'          => $l->item?->item_name,
                'item_spec'          => $l->item?->spec,
                'item_material'      => $l->item?->material,
                'spec_unit'          => $l->item?->spec_unit,
                'lot_no'             => $l->lot?->lot_no,
                'warehouse_name'     => $l->warehouse?->warehouse_name,
                'qty'                => (float) $l->qty,
                'status'             => $l->line_status,
                'remark'             => $l->txn?->remark,
                'user_name'          => $users[$l->txn?->create_account_idx] ?? '',
            ]);
        return response()->json([
            'status'     => $wo->status,
            'lots'       => $this->issues->returnPlan($wo),
            'history'    => $history,
            'warehouses' => Warehouse::options(),
            'now'        => now()->format('Y-m-d\TH:i'),
        ]);
    }

    public function returnSave(Request $request) {
        $data = $request->validate([
            'work_order_idx'          => 'required|integer',
            'occurred_at'             => 'required|date_format:Y-m-d\TH:i',
            'remark'                  => 'nullable|string|max:200',
            'lines'                   => 'required|array|min:1|max:500',
            'lines.*.item_idx'        => 'required|integer',
            'lines.*.lot_idx'         => 'required|integer|min:0',
            'lines.*.qty'             => 'required|numeric|min:0|max:999999999999',
            'lines.*.warehouse_idx'   => 'required|integer',
            'lines.*.mode'            => 'nullable|string|max:20',
        ], [], ['occurred_at' => '반납일시', 'remark' => '비고', 'lines' => '반납 수량', 'lines.*.qty' => '반납 수량', 'lines.*.warehouse_idx' => '창고']);

        [$txn, $warnings] = $this->issues->returnMaterials($this->find($data['work_order_idx']), $data);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'txn_no' => $txn->txn_no]);
    }

    public function returnCancel(Request $request) {
        $data = $request->validate([
            'work_order_idx'        => 'required|integer',
            'stock_txn_line_idxs'   => 'required|array|min:1|max:200',
            'stock_txn_line_idxs.*' => 'integer',
        ]);
        $n = $this->issues->returnCancel($this->find($data['work_order_idx']), $data['stock_txn_line_idxs']);
        return response()->json(['message' => 'Success', 'canceled' => $n]);
    }

    private function find(int $woIdx): WorkOrder {
        $wo = WorkOrder::with(['item', 'lot'])->find($woIdx);
        if (!$wo) {
            abort(404);
        }
        return $wo;
    }
}

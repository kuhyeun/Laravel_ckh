<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Models\Partner;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Purchase\Services\ReceiptService;
use MesCore\Quality\Services\IncomingInspectionService;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 입고검사 ( QUA-01 ) — docs/screens/40-구매자재.md §12
//   검사완료 창은 입고관리( PUR-03 )에서도 연다 ( C안 ) → 권한은 라우트가 아니라 여기서,
//   입고검사 · 입고관리 어느 쪽 권한으로도 ( 보기 can_read · 검사완료 / 취소 can_write )
class IncomingInspectionController extends Controller {
    use RespondsWithList;

    private const SCREENS  = ['Quality/IncomingInspection', 'Purchase/ReceiptManage'];
    private const SORTABLE = ['occurred_at', 'txn_no'];

    public function __construct(
        private IncomingInspectionService $inspections,
        private MenuPermissionService $permissions,
    ) {}

    // 목록 : 검사 대상 줄 ( 무검사 제외 ). 기본 검사대기
    public function list(Request $request) {
        $this->requireScreen('can_read');
        $v = $request->validate([
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(['WAIT', 'DONE', 'CANCELED'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = StockTxnLine::query()
            ->select('stock_txn_line.*')
            ->join('stock_txn', 'stock_txn.stock_txn_idx', '=', 'stock_txn_line.stock_txn_idx')
            ->addSelect(['stock_txn.txn_no', 'stock_txn.occurred_at', 'stock_txn.partner_idx'])
            ->addSelect(['partner_name' => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'stock_txn.partner_idx')])
            ->where('stock_txn.txn_type', ReceiptService::TXN_TYPE)
            ->where('stock_txn_line.insp_mode', '!=', 'NONE')
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot', 'warehouse:warehouse_idx,warehouse_name'])
            ->orderBy('stock_txn.occurred_at')
            ->orderBy('stock_txn_line.stock_txn_line_idx');

        if (!empty($v['status']))      $query->where('stock_txn_line.line_status', $v['status']);
        if (!empty($v['from']))        $query->where('stock_txn.occurred_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))          $query->where('stock_txn.occurred_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['partner_idx'])) $query->where('stock_txn.partner_idx', $v['partner_idx']);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('stock_txn.txn_no', 'like', $kw)
                  ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw)->orWhere('external_lot_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'lines', fn (StockTxnLine $l) => [
            'stock_txn_line_idx' => $l->stock_txn_line_idx,
            'stock_txn_idx'      => $l->stock_txn_idx,
            'txn_no'             => $l->txn_no,
            'occurred_at'        => substr((string) $l->occurred_at, 0, 16),
            'partner_name'       => $l->partner_name,
            'item_code'          => $l->item?->item_code,
            'item_name'          => $l->item?->item_name,
            'item_spec'          => $l->item?->spec,
            'item_material'      => $l->item?->material,
            'spec_unit'          => $l->item?->spec_unit,
            'lot_no'             => $l->lot?->lot_no,
            'external_lot_no'    => $l->lot?->external_lot_no,
            'qty'                => (float) $l->qty,
            'pass_qty'           => (float) $l->pass_qty,
            'fail_qty'           => (float) $l->fail_qty,
            'insp_mode'          => $l->insp_mode,
            'line_status'        => $l->line_status,
        ]);
    }

    // 검사완료 창 : 줄 · LOT · 기준서 요약 · 지금 검사 기록
    public function show(int $lineIdx) {
        $this->requireScreen('can_read');
        $line = $this->findLine($lineIdx);

        $spec = InspectionSpec::withCount('lines')
            ->where('item_idx', $line->item_idx)->where('insp_type', 'INCOMING')->where('is_use', 'Y')
            ->first(['inspection_spec_idx', 'inspection_spec_name']);
        $inspection = $this->inspections->activeInspection($line);

        return response()->json([
            'line' => [
                'stock_txn_line_idx' => $line->stock_txn_line_idx,
                'txn_no'             => $line->txn->txn_no,
                'occurred_at'        => $line->txn->occurred_at?->format('Y-m-d H:i'),
                'partner_name'       => $line->txn->partner?->partner_name,
                'item_code'          => $line->item?->item_code,
                'item_name'          => $line->item?->item_name,
                'item_spec'          => $line->item?->spec,
                'item_material'      => $line->item?->material,
                'spec_unit'          => $line->item?->spec_unit,
                'lot_no'             => $line->lot?->lot_no,
                'external_lot_no'    => $line->lot?->external_lot_no,
                'spec_value'         => $line->lot?->spec_value !== null ? (float) $line->lot->spec_value : null,
                'warehouse_name'     => $line->warehouse?->warehouse_name,
                'qty'                => (float) $line->qty,
                'insp_mode'          => $line->insp_mode,
                'line_status'        => $line->line_status,
            ],
            'spec' => $spec ? [
                'inspection_spec_idx'  => $spec->inspection_spec_idx,
                'inspection_spec_name' => $spec->inspection_spec_name,
                'item_count'           => $spec->lines_count,
                'items'                => $spec->lines()->orderBy('seq')->limit(20)->pluck('inspection_item_name'),
            ] : null,
            'inspection' => $inspection ? [
                'inspection_idx' => $inspection->inspection_idx,
                'result'         => $inspection->result,
                'pass_qty'       => (float) $inspection->pass_qty,
                'fail_qty'       => (float) $inspection->fail_qty,
                'inspected_at'   => $inspection->inspected_at?->format('Y-m-d H:i'),
                'inspector_name' => $inspection->inspector?->user_name ?: $inspection->inspector?->user_id,
                'reason'         => $inspection->defects->first()?->reason,
                'defect_type'    => app(\MesCore\Quality\Services\DefectService::class)->typeName($inspection->defects->first()?->defect_type_code),
                'remark'         => $inspection->remark,
            ] : null,
            'inspectors' => User::where('is_use', 'Y')->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'now'        => now()->format('Y-m-d\TH:i'),
            'me'         => Auth::id(),
        ]);
    }

    public function complete(Request $request, int $lineIdx) {
        $this->requireScreen('can_write');
        $line = $this->findLine($lineIdx);

        $data = $request->validate([
            'pass_qty'      => 'required|numeric|min:0|max:999999999999',
            'fail_qty'      => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'        => 'nullable|string|max:200',
            'inspector_idx' => 'required|integer|exists:account_member,account_idx',
            'inspected_at'  => 'required|date_format:Y-m-d\TH:i',
            'remark'        => 'nullable|string|max:2000',
        ], [], [
            'pass_qty' => '합격', 'fail_qty' => '불합격', 'defect_type_code' => '불량 유형', 'reason' => '불량 상세', 'inspector_idx' => '검사자', 'inspected_at' => '검사일시',
        ]);

        try {
            $inspection = $this->inspections->complete($line, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('입고검사 완료 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json(['message' => 'Success', 'inspection_idx' => $inspection->inspection_idx, 'result' => $inspection->result]);
    }

    public function cancel(int $lineIdx) {
        $this->requireScreen('can_write');
        $this->inspections->cancel($this->findLine($lineIdx));
        return response()->json(['message' => 'Success']);
    }

    // ---- 내부 ----

    private function findLine(int $lineIdx): StockTxnLine {
        $line = StockTxnLine::with(['txn.partner', 'item', 'lot', 'warehouse'])->find($lineIdx);
        if (!$line || $line->txn?->txn_type !== ReceiptService::TXN_TYPE || $line->insp_mode === 'NONE') {
            abort(404, '검사할 입고 줄을 찾을 수 없습니다.');
        }
        return $line;
    }

    private function requireScreen(string $action): void {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null || !$this->permissions->canPage($user, self::SCREENS, $action)) {
            abort(403, 'Permission Denied.');
        }
    }
}

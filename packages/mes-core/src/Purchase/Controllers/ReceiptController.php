<?php

namespace MesCore\Purchase\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Config\Models\Warehouse;
use MesCore\Purchase\Models\PurchaseOrderLine;
use MesCore\Purchase\Services\ReceiptService;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\LotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 입고관리 ( PUR-03 ) — docs/screens/40-구매자재.md §11
//   목록 한 행 = 입고 한 건 + 줄( _children ). 기본 정렬은 등록 시각 최근 먼저 ( 재고-수불-설계 §4.3 )
//   수정은 없다 — 줄 취소( 역분개 ) 후 다시 입고. 검사완료는 입고검사 API( 같은 창 )
class ReceiptController extends Controller {
    use RespondsWithList;

    private const ATTRIBUTES = [
        'partner_idx'                     => '구매처',
        'occurred_at'                     => '입고일시',
        'lot_rule_idx'                    => 'LOT 규칙',
        'remark'                          => '비고',
        'lines'                           => '품목',
        'lines.*.item_idx'                => '품목',
        'lines.*.qty'                     => '입고 수량',
        'lines.*.warehouse_idx'           => '창고',
        'lines.*.spec_value'              => '규격',
        'lines.*.external_lot_no'         => '공급처 LOT',
        'lines.*.purchase_order_line_idx' => '발주',
    ];

    private const SORTABLE = ['txn_no', 'occurred_at', 'recorded_at', 'partner_name'];

    public function __construct(private ReceiptService $receipts, private LotService $lots) {}

    // 목록 : 입고일 기간 + 검색어( 입고번호 · 발주번호 · 품번 · 품명 · LOT · 공급처 LOT ) + 구매처 + 상태
    public function list(Request $request) {
        $v = $request->validate([
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(array_keys(StockTxn::STATUSES))],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = StockTxn::query()
            ->select('stock_txn.*')
            ->addSelect(['partner_name' => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'stock_txn.partner_idx')])
            ->where('txn_type', ReceiptService::TXN_TYPE)
            ->with(['lines.item:item_idx,item_code,item_name,item_kind,spec_unit', 'lines.lot', 'lines.warehouse:warehouse_idx,warehouse_name',
                    'lines.purchaseOrderLine.order:purchase_order_idx,order_no'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('stock_txn_idx');

        if (!empty($v['from'])) $query->where('occurred_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))   $query->where('occurred_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['partner_idx'])) $query->where('partner_idx', $v['partner_idx']);
        if (!empty($v['status']))      $query->where('status', $v['status']);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('txn_no', 'like', $kw)
                  ->orWhereHas('lines.item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lines.lot', fn ($q) => $q->where('lot_no', 'like', $kw)->orWhere('external_lot_no', 'like', $kw))
                  ->orWhereHas('lines.purchaseOrderLine.order', fn ($q) => $q->where('order_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'receipts', fn (StockTxn $t) => $this->toRow($t));
    }

    public function show(int $txnIdx) {
        $txn = StockTxn::with(['partner', 'lines.item', 'lines.lot', 'lines.warehouse', 'lines.purchaseOrderLine.order'])
            ->where('txn_type', ReceiptService::TXN_TYPE)->find($txnIdx);
        if (!$txn) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $row = $this->toRow($txn);
        $row['remark'] = $txn->remark;
        return response()->json(['receipt' => $row]);
    }

    // 등록 모달 : 구매처 · 품목 · 창고 · LOT 설정
    public function formData() {
        $partners = Partner::where('is_supplier', 'Y')->where('is_use', 'Y')
            ->orderBy('partner_name')->get(['partner_idx', 'partner_code', 'partner_name']);

        $items = Item::where('is_use', 'Y')
            ->orderByRaw("item_type = 'MATERIAL' DESC")->orderBy('item_code')
            ->get(['item_idx', 'item_code', 'item_name', 'item_type', 'item_kind', 'spec_unit']);

        $rules = $this->lots->materialRules()->map(fn ($r) => [
            'lot_rule_idx'  => $r->lot_rule_idx,
            'lot_rule_name' => $r->lot_rule_name,
            'partner_mode'  => $this->lots->partnerMode($r),
        ])->values();

        return response()->json([
            'partners'   => $partners,
            'items'      => $items,
            'warehouses' => Warehouse::options(),
            'lot'        => [
                'enabled' => $this->lots->enabled(),
                'source'  => $this->lots->materialSource(),
                'rules'   => $rules,
            ],
            'now'        => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // 발주 불러오기 : 그 구매처의 확정 발주 중 입고가 덜 된 줄
    public function poLines(Request $request) {
        $v = $request->validate(['partner_idx' => 'required|integer']);

        $lines = PurchaseOrderLine::with(['order:purchase_order_idx,order_no,order_date,status,partner_idx', 'item:item_idx,item_code,item_name,item_kind,spec_unit'])
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('partner_idx', $v['partner_idx'])->where('status', 'CONFIRMED'))
            ->orderBy('due_date')->orderBy('purchase_order_line_idx')
            ->get()
            ->map(fn (PurchaseOrderLine $l) => [
                'purchase_order_line_idx' => $l->purchase_order_line_idx,
                'order_no'                => $l->order->order_no,
                'line_no'                 => $l->line_no,
                'order_date'              => $l->order->order_date?->toDateString(),
                'due_date'                => $l->due_date?->toDateString(),
                'item_idx'                => $l->item_idx,
                'item_code'               => $l->item?->item_code,
                'item_name'               => $l->item?->item_name,
                'item_kind'               => $l->item?->item_kind,
                'spec_unit'               => $l->item?->spec_unit,
                'qty'                     => (float) $l->qty,
                'received_qty'            => (float) $l->received_qty,
                'remaining'               => $this->receipts->remainingQty($l),
            ])
            ->filter(fn ($l) => $l['remaining'] > 0)
            ->values();

        return response()->json(['lines' => $lines]);
    }

    // 줄마다 입고검사 방식 미리 보기 ( 저장할 때 서버가 다시 정한다 )
    public function inspModes(Request $request) {
        $v = $request->validate([
            'partner_idx' => 'required|integer',
            'item_idxs'   => 'required|array|max:200',
            'item_idxs.*' => 'integer',
        ]);
        $modes = collect(array_unique($v['item_idxs']))
            ->mapWithKeys(fn ($i) => [$i => $this->receipts->inspectionMode((int) $i, (int) $v['partner_idx'])]);

        return response()->json(['modes' => $modes]);
    }

    public function save(Request $request) {
        $data = $request->validate([
            'partner_idx'                     => 'required|integer',
            'occurred_at'                     => 'required|date_format:Y-m-d\TH:i',
            'lot_rule_idx'                    => 'nullable|integer',
            'with_partner'                    => 'nullable|boolean',
            'remark'                          => 'nullable|string|max:2000',
            'lines'                           => 'required|array|min:1|max:200',
            'lines.*.item_idx'                => 'required|integer',
            'lines.*.qty'                     => 'required|numeric|gt:0|max:999999999999',
            'lines.*.warehouse_idx'           => 'required|integer',
            'lines.*.spec_value'              => 'nullable|numeric|gt:0|max:999999999999',
            'lines.*.external_lot_no'         => 'nullable|string|max:50',
            'lines.*.purchase_order_line_idx' => 'nullable|integer',
            'lines.*.remark'                  => 'nullable|string|max:200',
        ], [
            'lines.required'   => '품목을 하나 이상 넣으세요.',
            'lines.min'        => '품목을 하나 이상 넣으세요.',
            'lines.*.qty.gt'   => '입고 수량은 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        try {
            [$txn, $warnings] = $this->receipts->create($data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('입고 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json([
            'message'  => 'Success',
            'warnings' => $warnings,
            'receipt'  => ['stock_txn_idx' => $txn->stock_txn_idx, 'txn_no' => $txn->txn_no, 'status' => $txn->status],
        ]);
    }

    // 줄 취소 ( 여러 줄 ) — 검사대기는 그냥, 입고완료는 역분개. 하나라도 막히면 전부 되돌린다
    public function cancel(Request $request) {
        $v = $request->validate([
            'line_idxs'   => 'required|array|min:1|max:200',
            'line_idxs.*' => 'integer',
        ]);

        $lines = StockTxnLine::with('txn')->whereIn('stock_txn_line_idx', $v['line_idxs'])
            ->whereHas('txn', fn ($q) => $q->where('txn_type', ReceiptService::TXN_TYPE))
            ->get();
        if ($lines->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        DB::transaction(function () use ($lines) {
            foreach ($lines as $line) {
                $this->receipts->cancelLine($line);
            }
        });

        return response()->json(['message' => 'Success', 'canceled' => $lines->count()]);
    }

    // ---- 내부 ----

    private function toRow(StockTxn $t): array {
        $lines = $t->lines;
        $first = $lines->first();
        $poNos = $lines->map(fn ($l) => $l->purchaseOrderLine?->order?->order_no)->filter()->unique()->values();
        $wait  = $lines->where('line_status', 'WAIT')->count();
        $units = $lines->map(fn ($l) => $l->item?->spec_unit)->unique();

        return [
            'stock_txn_idx' => $t->stock_txn_idx,
            'txn_no'        => $t->txn_no,
            'occurred_at'   => $t->occurred_at?->format('Y-m-d H:i'),
            'recorded_at'   => $t->recorded_at?->format('Y-m-d H:i'),
            'partner_idx'   => $t->partner_idx,
            'partner_name'  => $t->partner_name ?? $t->partner?->partner_name,
            'po_summary'    => $poNos->isEmpty() ? '( 직접 )' : $poNos->first() . ($poNos->count() > 1 ? ' 외 ' . ($poNos->count() - 1) : ''),
            'item_summary'  => $first ? $first->item?->item_code . ($lines->count() > 1 ? ' 외 ' . ($lines->count() - 1) : '') : '',
            'total_qty'     => $units->count() === 1 ? (float) $lines->where('line_status', '!=', 'CANCELED')->sum('qty') : null,
            'status'        => $t->status,
            'wait_count'    => $wait,
            'line_count'    => $lines->count(),
            '_children'     => $lines->map(fn (StockTxnLine $l) => [
                'stock_txn_idx'           => $t->stock_txn_idx,
                'stock_txn_line_idx'      => $l->stock_txn_line_idx,
                'is_line'                 => true,
                'txn_no'                  => $t->txn_no,
                'line_no'                 => $l->line_no,
                'item_idx'                => $l->item_idx,
                'item_code'               => $l->item?->item_code,
                'item_name'               => $l->item?->item_name,
                'spec_unit'               => $l->item?->spec_unit,
                'lot_no'                  => $l->lot?->lot_no,
                'external_lot_no'         => $l->lot?->external_lot_no,
                'spec_value'              => $l->lot?->spec_value !== null ? (float) $l->lot->spec_value : null,
                'warehouse_name'          => $l->warehouse?->warehouse_name,
                'order_no'                => $l->purchaseOrderLine?->order?->order_no,
                'qty'                     => (float) $l->qty,
                'pass_qty'                => (float) $l->pass_qty,
                'fail_qty'                => (float) $l->fail_qty,
                'insp_mode'               => $l->insp_mode,
                'line_status'             => $l->line_status,
                'has_inspection'          => $l->insp_mode !== 'NONE',
            ])->values()->all(),
        ];
    }
}

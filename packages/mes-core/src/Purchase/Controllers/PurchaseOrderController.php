<?php

namespace MesCore\Purchase\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Purchase\Models\PurchaseOrder;
use MesCore\Purchase\Models\PurchaseOrderLine;
use MesCore\Purchase\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 발주서관리 ( PUR-01 목록 · PUR-02 등록 / 수정 ) — docs/screens/40-구매자재.md
//   수주관리( SalesOrderController )와 같은 모양. 목록 한 행 = 발주 한 건 + 줄( _children )
class PurchaseOrderController extends Controller {
    use RespondsWithList;

    private const ATTRIBUTES = [
        'order_date'         => '발주일',
        'partner_idx'        => '구매처',
        'order_name'         => '발주명',
        'status'             => '상태',
        'remark'             => '비고',
        'lines'              => '품목',
        'lines.*.item_idx'   => '품목',
        'lines.*.qty'        => '수량',
        'lines.*.unit_price' => '단가',
        'lines.*.due_date'   => '입고 예정일',
        'lines.*.remark'     => '비고',
    ];

    private const SORTABLE = ['order_no', 'order_date', 'partner_name', 'next_due_date', 'total_amount'];

    public function __construct(private PurchaseOrderService $orders) {}

    // 목록 : 기간( 발주일 / 입고 예정일 ) + 검색어( 발주번호 · 발주명 · 품번 · 품명 ) + 구매처 + 상태
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis'  => 'nullable|in:order_date,due_date',
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(array_merge(['OPEN'], array_keys(PurchaseOrder::STATUSES)))],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = PurchaseOrder::query()
            ->select('purchase_order.*')
            ->addSelect([
                'partner_name'  => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'purchase_order.partner_idx'),
                // 입고가 끝나지 않은 줄 중 가장 이른 입고 예정일
                'next_due_date' => PurchaseOrderLine::selectRaw('MIN(due_date)')
                    ->whereColumn('purchase_order_line.purchase_order_idx', 'purchase_order.purchase_order_idx')
                    ->where('line_status', 'OPEN'),
            ])
            ->with(['lines.item:item_idx,item_code,item_name,spec,material,spec_unit'])
            ->orderByDesc('order_date')
            ->orderByDesc('order_no');

        $this->applyFilters($query, $v);

        return $this->listResponse($request, $query, self::SORTABLE, 'orders', fn (PurchaseOrder $o) => $this->toRow($o));
    }

    public function show(int $orderIdx) {
        $order = PurchaseOrder::with(['partner', 'lines.item'])->find($orderIdx);
        if (!$order) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['order' => $this->toForm($order)]);
    }

    // 등록 · 수정 모달용 : 구매처( 구매처 구분 · 사용 중 ) + 품목( 사용 중 — 자재 먼저 )
    public function formData() {
        $partners = Partner::where('is_supplier', 'Y')->where('is_use', 'Y')
            ->orderBy('partner_name')
            ->get(['partner_idx', 'partner_code', 'partner_name']);

        $items = Item::where('is_use', 'Y')
            ->orderByRaw("item_type = 'MATERIAL' DESC")
            ->orderBy('item_code')
            ->get(['item_idx', 'item_code', 'item_name', 'item_type', 'spec_unit', 'std_buy_price']);

        return response()->json([
            'partners' => $partners,
            'items'    => $items,
            'today'    => now()->toDateString(),
        ]);
    }

    public function save(Request $request) {
        $idx   = $request->input('purchase_order_idx');
        $order = $idx ? PurchaseOrder::find($idx) : null;
        if ($idx && !$order) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($order && !in_array($order->status, PurchaseOrder::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => [
                PurchaseOrder::STATUSES[$order->status] . ' 상태의 발주는 고칠 수 없습니다. [다시 열기] 후 고치세요.',
            ]]);
        }

        $data = $request->validate([
            'order_date'                      => 'required|date_format:Y-m-d',
            'partner_idx'                     => 'required|integer',
            'order_name'                      => 'nullable|string|max:100',
            'status'                          => ['required', Rule::in(PurchaseOrder::EDITABLE_STATUSES)],
            'remark'                          => 'nullable|string|max:2000',
            'lines'                           => 'required|array|min:1|max:200',
            'lines.*.purchase_order_line_idx' => 'nullable|integer',
            'lines.*.item_idx'                => 'required|integer',
            'lines.*.qty'                     => 'required|numeric|gt:0|max:999999999999',
            'lines.*.unit_price'              => 'nullable|numeric|min:0|max:9999999999999',
            'lines.*.due_date'                => 'required|date_format:Y-m-d',
            'lines.*.remark'                  => 'nullable|string|max:200',
        ], [
            'lines.required' => '품목을 하나 이상 넣으세요.',
            'lines.min'      => '품목을 하나 이상 넣으세요.',
            'lines.*.qty.gt' => '수량은 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        try {
            [$saved, $warnings] = $this->orders->save($data, $order);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('발주 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json([
            'message'  => 'Success',
            'warnings' => $warnings,
            'order'    => $this->toForm($saved),
        ]);
    }

    // 선택 삭제 : 입고가 없으면 지우고, 있으면 취소
    public function delete(Request $request) {
        $v = $request->validate([
            'purchase_order_idxs'   => 'required|array|min:1|max:500',
            'purchase_order_idxs.*' => 'integer',
        ]);

        $orders = PurchaseOrder::with('lines')->whereIn('purchase_order_idx', $v['purchase_order_idxs'])->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $result = ['deleted' => 0, 'canceled' => 0];
        try {
            DB::transaction(function () use ($orders, &$result) {
                foreach ($orders as $order) {
                    $result[$this->orders->delete($order)]++;
                }
            });
        } catch (\Throwable $e) {
            Log::error('발주 삭제 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json(['message' => 'Success'] + $result);
    }

    // 상태 바꾸기 : 마감( CLOSED ) · 다시 열기( CONFIRMED )
    public function status(Request $request) {
        $v = $request->validate([
            'purchase_order_idxs'   => 'required|array|min:1|max:500',
            'purchase_order_idxs.*' => 'integer',
            'status'                => 'required|in:CLOSED,CONFIRMED',
        ]);

        $orders = PurchaseOrder::whereIn('purchase_order_idx', $v['purchase_order_idxs'])->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        DB::transaction(function () use ($orders, $v) {
            foreach ($orders as $order) {
                $this->orders->changeStatus($order, $v['status']);
            }
        });

        return response()->json(['message' => 'Success', 'changed' => $orders->count()]);
    }

    // ---- 목록 ----

    private function applyFilters(Builder $query, array $v): void {
        $from = $v['from'] ?? null;
        $to   = $v['to'] ?? null;
        if ($from || $to) {
            if (($v['date_basis'] ?? 'order_date') === 'due_date') {
                $query->whereHas('lines', function ($q) use ($from, $to) {
                    if ($from) $q->where('due_date', '>=', $from);
                    if ($to)   $q->where('due_date', '<=', $to);
                });
            } else {
                if ($from) $query->where('order_date', '>=', $from);
                if ($to)   $query->where('order_date', '<=', $to);
            }
        }

        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function ($w) use ($kw) {
                $w->where('order_no', 'like', $kw)
                  ->orWhere('order_name', 'like', $kw)
                  ->orWhereHas('lines.item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw));
            });
        }

        if (!empty($v['partner_idx'])) {
            $query->where('partner_idx', $v['partner_idx']);
        }

        $status = $v['status'] ?? null;
        if ($status === 'OPEN') {
            $query->whereIn('status', ['DRAFT', 'CONFIRMED']);
        } elseif ($status) {
            $query->where('status', $status);
        }
    }

    private function toRow(PurchaseOrder $o): array {
        $lines = $o->lines;
        $first = $lines->first();
        $units = $lines->map(fn ($l) => $l->item?->spec_unit)->unique();

        return [
            'purchase_order_idx' => $o->purchase_order_idx,
            'order_no'           => $o->order_no,
            'order_date'         => $o->order_date?->toDateString(),
            'partner_idx'        => $o->partner_idx,
            'partner_name'       => $o->partner_name,
            'order_name'         => $o->order_name,
            'item_summary'       => $first ? \MesCore\Config\Models\Item::labelOf($first->item, $lines->count() > 1) . ($lines->count() > 1 ? ' 외 ' . ($lines->count() - 1) . '건' : '') : '',
            'total_qty'          => $units->count() === 1 ? (float) $lines->sum('qty') : null,
            'total_amount'       => (float) $o->total_amount,
            'next_due_date'      => $o->next_due_date,
            'status'             => $o->status,
            'line_count'         => $lines->count(),
            'done_count'         => $lines->where('line_status', 'DONE')->count(),
            '_children'          => $lines->map(fn (PurchaseOrderLine $l) => [
                'purchase_order_idx'      => $o->purchase_order_idx,
                'purchase_order_line_idx' => $l->purchase_order_line_idx,
                'is_line'                 => true,
                'order_no'                => $o->order_no,   // 엑셀 펼침용
                'order_date'              => $o->order_date?->toDateString(),
                'partner_name'            => $o->partner_name,
                'order_name'              => $o->order_name,
                'line_no'                 => $l->line_no,
                'item_code'               => $l->item?->item_code,
                'item_name'               => $l->item?->item_name,
                'item_spec'               => $l->item?->spec,
                'item_material'           => $l->item?->material,
                'spec_unit'               => $l->item?->spec_unit,
                'qty'                     => (float) $l->qty,
                'unit_price'              => (float) $l->unit_price,
                'amount'                  => (float) $l->amount,
                'due_date'                => $l->due_date?->toDateString(),
                'received_qty'            => (float) $l->received_qty,
                'line_status'             => $l->line_status,
                'status'                  => $o->status,
            ])->values()->all(),
        ];
    }

    private function toForm(PurchaseOrder $o): array {
        return [
            'purchase_order_idx' => $o->purchase_order_idx,
            'order_no'           => $o->order_no,
            'order_date'         => $o->order_date?->toDateString(),
            'partner_idx'        => $o->partner_idx,
            'partner_name'       => $o->partner?->partner_name,
            'partner_code'       => $o->partner?->partner_code,
            'order_name'         => $o->order_name,
            'status'             => $o->status,
            'total_amount'       => (float) $o->total_amount,
            'remark'             => $o->remark,
            'locked'             => $o->hasDownstream(),
            'lines'              => $o->lines->map(fn (PurchaseOrderLine $l) => [
                'purchase_order_line_idx' => $l->purchase_order_line_idx,
                'item_idx'                => $l->item_idx,
                'item_code'               => $l->item?->item_code,
                'item_name'               => $l->item?->item_name,
                'item_spec'               => $l->item?->spec,
                'item_material'           => $l->item?->material,
                'spec_unit'               => $l->item?->spec_unit,
                'qty'                     => (float) $l->qty,
                'unit_price'              => (float) $l->unit_price,
                'amount'                  => (float) $l->amount,
                'due_date'                => $l->due_date?->toDateString(),
                'received_qty'            => (float) $l->received_qty,
                'line_status'             => $l->line_status,
                'locked'                  => $l->hasDownstream(),
                'min_qty'                 => $l->minQty(),
                'remark'                  => $l->remark,
            ])->values()->all(),
        ];
    }
}

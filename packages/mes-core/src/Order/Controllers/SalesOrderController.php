<?php

namespace MesCore\Order\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Order\Models\SalesOrder;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Order\Services\SalesOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 수주관리 ( SAL-01 목록 · SAL-02 등록 / 수정 ) — docs/screens/30-수주.md
//   목록은 서버 페이징 ( RespondsWithList ). 한 행 = 수주 한 건 + 줄( _children — 화면의 트리 )
//   저장 · 채번 · 삭제 규칙은 SalesOrderService
class SalesOrderController extends Controller {
    use RespondsWithList;

    private const ATTRIBUTES = [
        'order_date'           => '수주일',
        'partner_idx'          => '고객사',
        'order_name'           => '수주명',
        'customer_po_no'       => '고객 발주번호',
        'status'               => '상태',
        'remark'               => '비고',
        'lines'                => '품목',
        'lines.*.item_idx'     => '품목',
        'lines.*.qty'          => '수량',
        'lines.*.unit_price'   => '단가',
        'lines.*.due_date'     => '납품일',
        'lines.*.remark'       => '비고',
    ];

    // 목록 정렬 허용 ( 화면 열 이름 → 쿼리 별칭과 같다 )
    private const SORTABLE = ['order_no', 'order_date', 'partner_name', 'next_due_date', 'total_amount'];

    public function __construct(private SalesOrderService $orders) {}

    // 목록 : 기간( 수주일 / 납품일 ) + 검색어( 수주번호 · 수주명 · 고객 발주번호 · 품번 · 품명 ) + 고객사 + 상태
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis'  => 'nullable|in:order_date,due_date',
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(array_merge(['OPEN'], array_keys(SalesOrder::STATUSES)))],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = SalesOrder::query()
            ->select('sales_order.*')
            ->addSelect([
                'partner_name'  => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'sales_order.partner_idx'),
                // 아직 다 나가지 않은 줄 중 가장 이른 납품일
                'next_due_date' => SalesOrderLine::selectRaw('MIN(due_date)')
                    ->whereColumn('sales_order_line.sales_order_idx', 'sales_order.sales_order_idx')
                    ->where('line_status', 'OPEN'),
            ])
            ->with(['lines.item:item_idx,item_code,item_name,spec,material,item_kind,spec_unit'])
            ->orderByDesc('order_date')
            ->orderByDesc('order_no');

        $this->applyFilters($query, $v);

        return $this->listResponse($request, $query, self::SORTABLE, 'orders', fn (SalesOrder $o) => $this->toRow($o));
    }

    public function show(int $orderIdx) {
        $order = SalesOrder::with(['partner', 'lines.item'])->find($orderIdx);
        if (!$order) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['order' => $this->toForm($order)]);
    }

    // 등록 · 수정 모달용 선택지 : 고객사( 고객사 구분 · 사용 중 ) + 품목( 제품 대분류 · 사용 중 )
    //   수정 화면의 기존 값이 목록에서 빠졌으면( 미사용으로 내림 ) 화면이 그 값만 덧붙여 보여 준다
    public function formData() {
        $partners = Partner::where('is_customer', 'Y')->where('is_use', 'Y')
            ->orderBy('partner_name')
            ->get(['partner_idx', 'partner_code', 'partner_name']);

        $items = Item::where('item_type', 'PRODUCT')->where('is_use', 'Y')
            ->orderBy('item_code')
            ->get(['item_idx', 'item_code', 'item_name', 'item_kind', 'spec_unit', 'std_sale_price']);

        return response()->json([
            'partners' => $partners,
            'items'    => $items,
            'today'    => now()->toDateString(),
        ]);
    }

    public function save(Request $request) {
        $idx   = $request->input('sales_order_idx');
        $order = $idx ? SalesOrder::find($idx) : null;
        if ($idx && !$order) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($order && !in_array($order->status, SalesOrder::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => [
                SalesOrder::STATUSES[$order->status] . ' 상태의 수주는 고칠 수 없습니다. [다시 열기] 후 고치세요.',
            ]]);
        }

        $data = $request->validate([
            'order_date'                   => 'required|date_format:Y-m-d',
            'partner_idx'                  => 'required|integer',
            'order_name'                   => 'nullable|string|max:100',
            'customer_po_no'               => 'nullable|string|max:50',
            'status'                       => ['required', Rule::in(SalesOrder::EDITABLE_STATUSES)],
            'remark'                       => 'nullable|string|max:2000',
            'lines'                        => 'required|array|min:1|max:200',
            'lines.*.sales_order_line_idx' => 'nullable|integer',
            'lines.*.item_idx'             => 'required|integer',
            'lines.*.qty'                  => 'required|numeric|gt:0|max:999999999999',
            'lines.*.unit_price'           => 'nullable|numeric|min:0|max:9999999999999',
            'lines.*.due_date'             => 'required|date_format:Y-m-d',
            'lines.*.remark'               => 'nullable|string|max:200',
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
            Log::error('수주 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json([
            'message'  => 'Success',
            'warnings' => $warnings,
            'order'    => $this->toForm($saved),
        ]);
    }

    // 선택 삭제 : 아래 단계가 없으면 지우고, 있으면 취소 ( §6 )
    public function delete(Request $request) {
        $v = $request->validate([
            'sales_order_idxs'   => 'required|array|min:1|max:500',
            'sales_order_idxs.*' => 'integer',
        ]);

        $orders = SalesOrder::with('lines')->whereIn('sales_order_idx', $v['sales_order_idxs'])->get();
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
            Log::error('수주 삭제 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json(['message' => 'Success'] + $result);
    }

    // 상태 바꾸기 : 마감( CLOSED ) · 다시 열기( CONFIRMED )
    public function status(Request $request) {
        $v = $request->validate([
            'sales_order_idxs'   => 'required|array|min:1|max:500',
            'sales_order_idxs.*' => 'integer',
            'status'             => 'required|in:CLOSED,CONFIRMED',
        ]);

        $orders = SalesOrder::whereIn('sales_order_idx', $v['sales_order_idxs'])->get();
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
                // 납품일 : 기간 안에 납품일이 있는 줄이 하나라도 있는 수주
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
                  ->orWhere('customer_po_no', 'like', $kw)
                  ->orWhereHas('lines.item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw));
            });
        }

        if (!empty($v['partner_idx'])) {
            $query->where('partner_idx', $v['partner_idx']);
        }

        $status = $v['status'] ?? null;
        if ($status === 'OPEN') {
            $query->whereIn('status', ['DRAFT', 'CONFIRMED']);   // 진행 중
        } elseif ($status) {
            $query->where('status', $status);
        }
    }

    // 목록 한 행 = 머리 + 줄( _children ). 줄은 트리의 아래 행으로 그린다
    private function toRow(SalesOrder $o): array {
        $lines = $o->lines;
        $first = $lines->first();
        $units = $lines->map(fn ($l) => $l->item?->spec_unit)->unique();

        return [
            'sales_order_idx' => $o->sales_order_idx,
            'order_no'        => $o->order_no,
            'order_date'      => $o->order_date?->toDateString(),
            'partner_idx'     => $o->partner_idx,
            'partner_name'    => $o->partner_name,
            'order_name'      => $o->order_name,
            'customer_po_no'  => $o->customer_po_no,
            'item_summary'    => $first ? \MesCore\Config\Models\Item::labelOf($first->item, $lines->count() > 1) . ($lines->count() > 1 ? ' 외 ' . ($lines->count() - 1) . '건' : '') : '',
            'total_qty'       => $units->count() === 1 ? (float) $lines->sum('qty') : null,   // 단위가 섞이면 비움
            'total_amount'    => (float) $o->total_amount,
            'next_due_date'   => $o->next_due_date,
            'status'          => $o->status,
            'line_count'      => $lines->count(),
            '_children'       => $lines->map(fn (SalesOrderLine $l) => [
                'sales_order_idx'      => $o->sales_order_idx,
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'is_line'              => true,
                'order_no'             => $o->order_no,   // 엑셀 펼침용
                'order_date'           => $o->order_date?->toDateString(),
                'partner_name'         => $o->partner_name,
                'order_name'           => $o->order_name,
                'line_no'              => $l->line_no,
                'item_code'            => $l->item?->item_code,
                'item_name'            => $l->item?->item_name,
                'item_spec'            => $l->item?->spec,
                'item_material'        => $l->item?->material,
                'spec_unit'            => $l->item?->spec_unit,
                'qty'                  => (float) $l->qty,
                'unit_price'           => (float) $l->unit_price,
                'amount'               => (float) $l->amount,
                'due_date'             => $l->due_date?->toDateString(),
                'line_status'          => $l->line_status,
                'status'               => $o->status,
            ])->values()->all(),
        ];
    }

    // 수정 모달용
    private function toForm(SalesOrder $o): array {
        return [
            'sales_order_idx' => $o->sales_order_idx,
            'order_no'        => $o->order_no,
            'order_date'      => $o->order_date?->toDateString(),
            'partner_idx'     => $o->partner_idx,
            'partner_name'    => $o->partner?->partner_name,
            'partner_code'    => $o->partner?->partner_code,
            'order_name'      => $o->order_name,
            'customer_po_no'  => $o->customer_po_no,
            'status'          => $o->status,
            'total_amount'    => (float) $o->total_amount,
            'remark'          => $o->remark,
            'locked'          => $o->hasDownstream(),
            'lines'           => $o->lines->map(fn (SalesOrderLine $l) => [
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'item_idx'             => $l->item_idx,
                'item_code'            => $l->item?->item_code,
                'item_name'            => $l->item?->item_name,
                'item_spec'            => $l->item?->spec,
                'item_material'        => $l->item?->material,
                'spec_unit'            => $l->item?->spec_unit,
                'qty'                  => (float) $l->qty,
                'unit_price'           => (float) $l->unit_price,
                'amount'               => (float) $l->amount,
                'due_date'             => $l->due_date?->toDateString(),
                'produced_qty'         => (float) $l->produced_qty,
                'shipped_qty'          => (float) $l->shipped_qty,
                'line_status'          => $l->line_status,
                'locked'               => $l->hasDownstream(),
                'min_qty'              => $l->minQty(),
                'directed_qty'         => $l->directedQty(),   // 작업지시에 담긴 수량 ( 수주 창의 [작업지시] )
                'remark'               => $l->remark,
            ])->values()->all(),
        ];
    }
}

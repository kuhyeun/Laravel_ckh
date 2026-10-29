<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Shipment\Services\ShipmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// 출하관리 ( 7001 · SHP-01 · SHP-02 ) — docs/screens/60-출하.md
//   목록 = 출하 건 + 줄 펼침 · 등록 / 수정 = 출하대기 · 결재 요청 · 승인 · 반려 · 출하처리 · 취소
//   처리 규칙 · 단계 판정은 ShipmentService
class ShipmentController extends Controller {
    use RespondsWithList;

    private const SORTABLE = ['shipment_no', 'plan_date', 'shipped_at'];

    private const DATE_COLUMNS = ['plan' => 'plan_date', 'shipped' => 'shipped_at'];

    public function __construct(private ShipmentService $shipments) {}

    protected function listTieBreaker(): ?string {
        return 'shipment_idx';
    }

    // 목록 : 기간( 출하 예정 / 출하 일시 ) + 검색어( 출하번호 · 고객사 · 품번 · 품명 · 수주번호 ) + 상태( 기본 진행 중 = 대기 )
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis' => ['nullable', Rule::in(array_keys(self::DATE_COLUMNS))],
            'from'       => 'nullable|date_format:Y-m-d',
            'to'         => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'    => 'nullable|string|max:100',
            'status'     => ['nullable', Rule::in(['OPEN', 'ALL', 'DONE', 'CANCELED'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $col   = self::DATE_COLUMNS[$v['date_basis'] ?? 'plan'];
        $query = Shipment::query()
            ->with(['partner:partner_idx,partner_name', 'lines.item:item_idx,item_code,item_name,spec_unit', 'lines.salesOrderLine.order:sales_order_idx,order_no', 'lines.picks.lot:lot_idx,lot_no'])
            ->orderByDesc('plan_date')->orderByDesc('shipment_idx');

        $status = $v['status'] ?? 'OPEN';
        if ($status === 'OPEN') $query->where('status', 'WAIT');
        elseif ($status !== 'ALL') $query->where('status', $status);
        if (!empty($v['from'])) $query->where($col, '>=', $v['from']);
        if (!empty($v['to']))   $col === 'plan_date' ? $query->where($col, '<=', $v['to']) : $query->where($col, '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('shipment_no', 'like', $kw)
                  ->orWhereHas('partner', fn ($q) => $q->where('partner_name', 'like', $kw))
                  ->orWhereHas('lines.item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lines.salesOrderLine.order', fn ($q) => $q->where('order_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'shipments', function (Shipment $s) {
            $lines = $s->lines->map(fn (ShipmentLine $l) => $this->lineRow($l))->values();
            $stage = $this->shipments->stage($s);
            $head  = [
                'shipment_idx' => $s->shipment_idx,
                'shipment_no'  => $s->shipment_no,
                'plan_date'    => $s->plan_date?->toDateString(),
                'shipped_at'   => $s->shipped_at?->format('Y-m-d H:i'),
                'partner_name' => $s->partner?->partner_name,
                'status'       => $s->status,
                'stage'        => $stage,
                'stage_label'  => ShipmentService::STAGES[$stage] ?? $stage,
                'line_count'   => $lines->count(),
            ];
            // 줄이 하나면 그 줄 값을 머리 행에, 여럿이면 펼침
            if ($lines->count() === 1) {
                return $head + array_diff_key($lines[0], array_flip(['line_no']));
            }
            $first = $lines->first();
            return $head + [
                'item_label'    => $first ? "{$first['item_code']} 외 " . ($lines->count() - 1) . '건' : '',
                'order_summary' => $lines->pluck('order_label')->filter()->unique()->implode(', '),
                '_children'     => $lines->map(fn ($l) => $l + ['shipment_idx' => $s->shipment_idx, 'shipment_no' => $s->shipment_no . '-' . str_pad((string) $l['line_no'], 2, '0', STR_PAD_LEFT)])->all(),
            ];
        });
    }

    public function show(int $shipmentIdx) {
        $s = Shipment::with(['partner', 'lines.item', 'lines.salesOrderLine.order', 'lines.picks.lot', 'lines.picks.warehouse'])->find($shipmentIdx);
        if (!$s) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $users = User::pluck('user_name', 'account_idx');
        $stage = $this->shipments->stage($s);
        return response()->json(['shipment' => [
            'shipment_idx'          => $s->shipment_idx,
            'shipment_no'           => $s->shipment_no,
            'partner_idx'           => $s->partner_idx,
            'partner_name'          => $s->partner?->partner_name,
            'plan_date'             => $s->plan_date?->toDateString(),
            'shipped_at'            => $s->shipped_at?->format('Y-m-d H:i'),
            'status'                => $s->status,
            'stage'                 => $stage,
            'stage_label'           => ShipmentService::STAGES[$stage] ?? $stage,
            'approval_status'       => $s->approval_status,
            'approval_requested'    => $s->approval_requested_at ? trim(($users[$s->approval_requested_by] ?? '') . ' ' . $s->approval_requested_at->format('Y-m-d H:i')) : null,
            'approved'              => $s->approved_at ? trim(($users[$s->approved_by] ?? '') . ' ' . $s->approved_at->format('Y-m-d H:i')) : null,
            'reject_reason'         => $s->reject_reason,
            'shipped_by'            => $users[$s->shipped_by] ?? null,
            'canceled'              => $s->canceled_at ? trim(($users[$s->canceled_by] ?? '') . ' ' . $s->canceled_at->format('Y-m-d H:i')) : null,
            'remark'                => $s->remark,
            'lines'                 => $s->lines->map(fn (ShipmentLine $l) => $this->lineRow($l) + [
                'shipment_line_idx'    => $l->shipment_line_idx,
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'item_idx'             => $l->item_idx,
                'remark'               => $l->remark,
                'so_qty'               => $l->salesOrderLine ? (float) $l->salesOrderLine->qty : null,
                'so_remaining'         => $l->salesOrderLine ? $this->shipments->soRemaining($l->salesOrderLine, $s->shipment_idx) : null,
                'due_date'             => $l->salesOrderLine?->due_date?->toDateString(),
                'inspect_required'     => $this->shipments->inspectRequired((int) $l->item_idx),
                'inspected'            => $l->inspected_at ? trim(($users[$l->inspected_by] ?? '') . ' ' . $l->inspected_at->format('Y-m-d H:i')) : null,
                'inspect_remark'       => $l->inspect_remark,
                'picks'                => $l->picks->map(fn ($p) => [
                    'lot_idx' => (int) $p->lot_idx, 'warehouse_idx' => (int) $p->warehouse_idx, 'qty' => (float) $p->qty,
                    'lot_no' => $p->lot?->lot_no, 'warehouse_name' => $p->warehouse?->warehouse_name,
                ])->values(),
            ])->values(),
        ]]);
    }

    // 선택지 : 고객사 · 품목( 제품 ) · 결재 사용 여부
    public function formData() {
        return response()->json([
            'partners'          => Partner::where('is_customer', 'Y')->where('is_use', 'Y')->orderBy('partner_name')->get(['partner_idx', 'partner_code', 'partner_name']),
            'items'             => Item::where('item_type', 'PRODUCT')->where('is_use', 'Y')->orderBy('item_code')->get(['item_idx', 'item_code', 'item_name', 'spec_unit', 'std_sale_price']),
            'approval_required' => $this->shipments->approvalRequired(),
            'today'             => now()->toDateString(),
            'now'               => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // 수주 줄 고르기 : 그 고객사 · 확정 · 진행 줄 · 남은 출하 > 0 ( 납품일 이른 순 )
    public function soLines(Request $request) {
        $v = $request->validate(['partner_idx' => 'required|integer', 'shipment_idx' => 'nullable|integer']);
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,partner_idx,status', 'item:item_idx,item_code,item_name,spec_unit'])
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('partner_idx', $v['partner_idx'])->where('status', 'CONFIRMED'))
            ->orderBy('due_date')->orderBy('sales_order_line_idx')->get()
            ->map(fn (SalesOrderLine $l) => [
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'order_no'     => $l->order?->order_no,
                'line_no'      => $l->line_no,
                'item_idx'     => $l->item_idx,
                'item_code'    => $l->item?->item_code,
                'item_name'    => $l->item?->item_name,
                'spec_unit'    => $l->item?->spec_unit,
                'due_date'     => $l->due_date?->toDateString(),
                'qty'          => (float) $l->qty,
                'unit_price'   => $l->unit_price !== null ? (float) $l->unit_price : null,
                'produced_qty' => (float) $l->produced_qty,
                'shipped_qty'  => (float) $l->shipped_qty,
                'remaining'    => $this->shipments->soRemaining($l, $v['shipment_idx'] ?? null),
            ])->filter(fn ($l) => $l['remaining'] > 0.000001)->values();
        return response()->json(['lines' => $lines]);
    }

    // LOT 후보 ( 선점 반영 — 이 출하의 지정분은 빼고 계산 )
    public function candidates(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'shipment_idx' => 'nullable|integer']);
        return response()->json(['lots' => $this->shipments->candidates((int) $v['item_idx'], $v['shipment_idx'] ?? null)]);
    }

    public function save(Request $request) {
        $idx = $request->input('shipment_idx');
        $s   = $idx ? Shipment::find($idx) : null;
        if ($idx && !$s) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $data = $request->validate([
            'partner_idx'                   => 'required|integer|exists:partner,partner_idx',
            'plan_date'                     => 'required|date_format:Y-m-d',
            'remark'                        => 'nullable|string|max:1000',
            'lines'                         => 'required|array|min:1|max:200',
            'lines.*.shipment_line_idx'     => 'nullable|integer',
            'lines.*.sales_order_line_idx'  => 'nullable|integer',
            'lines.*.item_idx'              => 'required|integer',
            'lines.*.qty'                   => 'required|numeric|gt:0|max:999999999999',
            'lines.*.unit_price'            => 'nullable|numeric|min:0|max:9999999999999999',
            'lines.*.remark'                => 'nullable|string|max:200',
            'lines.*.picks'                 => 'nullable|array|max:100',
            'lines.*.picks.*.lot_idx'       => 'required|integer|min:0',
            'lines.*.picks.*.warehouse_idx' => 'required|integer',
            'lines.*.picks.*.qty'           => 'required|numeric|min:0',
        ], ['lines.*.qty.gt' => '출하 수량은 0보다 커야 합니다.'], [
            'partner_idx' => '고객사', 'plan_date' => '출하 예정일', 'lines' => '출하 줄', 'lines.*.item_idx' => '품목', 'lines.*.qty' => '출하 수량', 'lines.*.unit_price' => '단가',
        ]);
        [$saved, $warnings] = $this->shipments->save($data, $s);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'shipment' => ['shipment_idx' => $saved->shipment_idx, 'shipment_no' => $saved->shipment_no]]);
    }

    public function requestApproval(Request $request) {
        $this->shipments->requestApproval($this->find($request));
        return response()->json(['message' => 'Success']);
    }

    public function approve(Request $request) {
        $this->shipments->approve($this->find($request));
        return response()->json(['message' => 'Success']);
    }

    public function reject(Request $request) {
        $reason = $request->validate(['reason' => 'required|string|max:200'], [], ['reason' => '반려 사유'])['reason'];
        $this->shipments->reject($this->find($request), $reason);
        return response()->json(['message' => 'Success']);
    }

    public function process(Request $request) {
        $at = $request->validate(['shipped_at' => 'required|date_format:Y-m-d\TH:i'], [], ['shipped_at' => '출하일시'])['shipped_at'];
        $warnings = $this->shipments->process($this->find($request), $at);
        return response()->json(['message' => 'Success', 'warnings' => $warnings]);
    }

    // 취소 : 체크한 건 ( 대기 = 상태만 · 완료 = 역분개 )
    public function cancel(Request $request) {
        $v = $request->validate(['shipment_idxs' => 'required|array|min:1|max:100', 'shipment_idxs.*' => 'integer']);
        $n = 0;
        foreach (Shipment::whereIn('shipment_idx', $v['shipment_idxs'])->get() as $s) {
            $this->shipments->cancel($s);
            $n++;
        }
        return response()->json(['message' => 'Success', 'canceled' => $n]);
    }

    // ---- 내부 ----

    private function find(Request $request): Shipment {
        $s = Shipment::find($request->validate(['shipment_idx' => 'required|integer'])['shipment_idx']);
        if (!$s) {
            abort(404);
        }
        return $s;
    }

    private function lineRow(ShipmentLine $l): array {
        $so = $l->salesOrderLine;
        return [
            'line_no'        => $l->line_no,
            'item_code'      => $l->item?->item_code,
            'item_name'      => $l->item?->item_name,
            'item_label'     => trim(($l->item?->item_code ?? '') . ' ' . ($l->item?->item_name ?? '')),
            'spec_unit'      => $l->item?->spec_unit,
            'qty'            => (float) $l->qty,
            'unit_price'     => $l->unit_price !== null ? (float) $l->unit_price : null,
            'order_label'    => $so?->order ? "{$so->order->order_no} #{$so->line_no}" : null,
            'order_summary'  => $so?->order ? "{$so->order->order_no} #{$so->line_no}" : '( 품목 출하 )',
            'inspect_status' => $l->inspect_status,
            'lot_summary'    => $l->picks->map(fn ($p) => $p->lot?->lot_no)->filter()->unique()->implode(', '),
        ];
    }
}

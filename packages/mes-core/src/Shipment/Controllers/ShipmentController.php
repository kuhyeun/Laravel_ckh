<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Config\Models\PartnerSite;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Shipment\Services\ShipmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 출하관리 ( 7001 · SHP-01 · SHP-02 ) — docs/screens/60-출하.md
//   목록 = 출하 건 + 줄 펼침 · 등록 / 수정 = 출하대기 · 결재 요청 · 승인 · 반려 · 출하처리 · 취소
//   처리 규칙 · 단계 판정은 ShipmentService
class ShipmentController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SORTABLE = ['shipment_no', 'plan_date', 'shipped_at'];

    // 품목 · 규격 · 재질 열 정렬 ( 11-25 ) : 품목 = 첫 줄 품번 / 규격 · 재질 = 줄이 하나일 때만 ( 머리 행 값과 같게 )
    private static function sortable(): array {
        $first = fn (string $col) => DB::raw("( SELECT i.{$col} FROM shipment_line l JOIN item i ON i.item_idx = l.item_idx WHERE l.shipment_idx = shipment.shipment_idx ORDER BY l.line_no LIMIT 1 )");
        $only  = fn (string $col) => DB::raw("( SELECT MAX(i.{$col}) FROM shipment_line l JOIN item i ON i.item_idx = l.item_idx WHERE l.shipment_idx = shipment.shipment_idx HAVING COUNT(*) = 1 )");
        return [...self::SORTABLE, 'item_label' => $first('item_code'), 'item_spec' => $only('spec'), 'item_material' => $only('material')];
    }

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
            'status'     => ['nullable', Rule::in(['OPEN', 'DONE', 'CANCELED'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $col   = self::DATE_COLUMNS[$v['date_basis'] ?? 'plan'];
        $query = Shipment::query()
            ->with(['partner:partner_idx,partner_name', 'site:site_idx,site_name', 'lines.item:item_idx,item_code,item_name,spec,material,spec_unit', 'lines.salesOrderLine.order:sales_order_idx,order_no', 'lines.picks.lot:lot_idx,lot_no,lot_title'])
            ->orderByDesc('plan_date')->orderByDesc('shipment_idx');

        $status = $v['status'] ?? '';   // 없으면 전체 ( 화면 기본값은 blankFilters 가 늘 보낸다 — 스타일 가이드 §3 )
        if ($status === 'OPEN') $query->where('status', 'WAIT');
        elseif ($status !== '') $query->where('status', $status);
        if (!empty($v['from'])) $query->where($col, '>=', $v['from']);
        if (!empty($v['to']))   $col === 'plan_date' ? $query->where($col, '<=', $v['to']) : $query->where($col, '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'shipment_no'  => fn ($q, $kw) => $q->where('shipment_no', 'like', $kw),
            'partner_name' => fn ($q, $kw) => $q->whereHas('partner', fn ($r) => $r->where('partner_name', 'like', $kw)),
            'item_code'    => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name'    => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'order_no'     => fn ($q, $kw) => $q->whereHas('lines.salesOrderLine.order', fn ($r) => $r->where('order_no', 'like', $kw)),
        ]);

        return $this->listResponse($request, $query, self::sortable(), 'shipments', function (Shipment $s) {
            $lines = $s->lines->map(fn (ShipmentLine $l) => $this->lineRow($l))->values();
            $stage = $this->shipments->stage($s);
            $head  = [
                'shipment_idx' => $s->shipment_idx,
                'shipment_no'  => $s->shipment_no,
                'plan_date'    => $s->plan_date?->toDateString(),
                'shipped_at'   => $s->shipped_at?->format('Y-m-d H:i'),
                'partner_name' => $s->partner?->partner_name,
                'manager_name' => $s->manager_name,
                'site_name'    => $s->site?->site_name,
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
                'item_label'    => $first ? "{$first['item_short']} 외 " . ($lines->count() - 1) . '건' : '',
                'order_summary' => $lines->pluck('order_label')->filter()->unique()->implode(', '),
                '_children'     => $lines->map(fn ($l) => $l + ['shipment_idx' => $s->shipment_idx, 'shipment_no' => $s->shipment_no . ' - ' . $l['line_no']])->all(),   // 줄 : 출하번호 - 줄 번호 ( 스타일 가이드 §5 트리 )
            ];
        });
    }

    public function show(int $shipmentIdx) {
        $s = Shipment::with(['partner', 'site', 'lines.item', 'lines.salesOrderLine.order', 'lines.picks.lot', 'lines.picks.warehouse'])->find($shipmentIdx);
        if (!$s) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $users = User::namesFor(array_merge([$s->approval_requested_by, $s->approved_by, $s->shipped_by, $s->canceled_by], $s->lines->pluck('inspected_by')->all()));
        $stage = $this->shipments->stage($s);
        return response()->json(['shipment' => [
            'shipment_idx'          => $s->shipment_idx,
            'shipment_no'           => $s->shipment_no,
            'partner_idx'           => $s->partner_idx,
            'partner_name'          => $s->partner?->partner_name,
            'manager_name'          => $s->manager_name,
            'site_idx'              => $s->site_idx,
            'site_name'             => $s->site?->site_name,
            'ship_address'          => $s->ship_address,
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

    // 선택지 : 고객사 · 출고지( 고객사별 ) · 결재 사용 여부
    //   주소 : 출고지를 고르면 그 주소, 출고지가 없으면 거래처 주소로 채운다 ( 화면 — 고칠 수 있다 )
    public function formData() {
        $partners = Partner::where('is_customer', 'Y')->where('is_use', 'Y')->orderBy('partner_name')->forPicker()->get();
        $ids      = $partners->pluck('partner_idx');
        return response()->json([
            'partners'          => $partners,
            'partner_addresses' => Partner::whereIn('partner_idx', $ids)->whereNotNull('address')->pluck('address', 'partner_idx'),
            'sites'             => PartnerSite::whereIn('partner_idx', $ids)->orderBy('sort_order')->orderBy('site_idx')
                ->get(['site_idx', 'partner_idx', 'site_name', 'address'])->groupBy('partner_idx'),
            // 품목 선택지는 보내지 않는다 — 화면의 ItemPicker 가 공용 검색( common.items.search )에서 30개씩
            'approval_required' => $this->shipments->approvalRequired(),
            'today'             => now()->toDateString(),
            'now'               => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // 수주 줄 고르기 : 그 고객사 · 확정 · 진행 줄 · 남은 출하 > 0 ( 납품일 이른 순 )
    public function soLines(Request $request) {
        $v = $request->validate(['partner_idx' => 'required|integer', 'shipment_idx' => 'nullable|integer']);
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,partner_idx,status', 'order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,spec,material,spec_unit'])
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('partner_idx', $v['partner_idx'])->where('status', 'CONFIRMED'))
            ->orderBy('due_date')->orderBy('sales_order_line_idx')->get();
        return response()->json(['lines' => $this->soLineRows($lines, $v['shipment_idx'] ?? null)]);
    }

    // 수주 줄 → 화면 줄 ( 남은 출하 수량이 있는 것만 ) — soLines · lotOrders 같이
    private function soLineRows($lines, ?int $shipmentIdx) {
        $waiting = $this->shipments->waitingByLine($lines->pluck('sales_order_line_idx')->all(), $shipmentIdx);   // 한 번에 ( 전에는 줄마다 )
        return $lines
            ->map(fn (SalesOrderLine $l) => [
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'partner_idx'  => $l->order?->partner_idx,
                'partner_name' => $l->order?->partner?->partner_name,
                'order_no'     => $l->order?->order_no,
                'line_no'      => $l->line_no,
                'item_idx'     => $l->item_idx,
                'item_code'    => $l->item?->item_code,
                'item_name'    => $l->item?->item_name,
                'item_spec'    => $l->item?->spec,
                'item_material' => $l->item?->material,
                'spec_unit'    => $l->item?->spec_unit,
                'due_date'     => $l->due_date?->toDateString(),
                'qty'          => (float) $l->qty,
                'unit_price'   => $l->unit_price !== null ? (float) $l->unit_price : null,
                'produced_qty' => (float) $l->produced_qty,
                'shipped_qty'  => (float) $l->shipped_qty,
                'remaining'    => $this->shipments->soRemaining($l, $shipmentIdx, $waiting[$l->sales_order_line_idx] ?? 0.0),
            ])->filter(fn ($l) => $l['remaining'] > 0.000001)->values();
    }

    /**
     * 현품표 스캔 ( 11-25 ) — 그 제품 LOT 이 어느 수주에서 나왔는지 : LOT → 생산 입고 · 지시 LOT → 작업지시 → work_order_source → 수주 줄
     *   LOT 전환( 분리 · 다른 품목 )으로 생긴 LOT 은 원 LOT 을 따라 올라간다
     *   lines = 출하할 수 있는 수주 줄( 확정 · 열림 · 남은 수량 ) — 납기 순 / had_orders = 수주에서 나온 LOT 인지 ( 다 출하된 경우 구분 )
     */
    public function lotOrders(Request $request) {
        $v = $request->validate(['lot_idx' => 'required|integer', 'shipment_idx' => 'nullable|integer']);
        $lots = [(int) $v['lot_idx']];
        $cur  = (int) $v['lot_idx'];
        for ($i = 0; $i < 10; $i++) {
            $p = DB::table('lot')->where('lot_idx', $cur)->where('source', 'CONVERT')->value('parent_lot_idx');
            if (!$p || in_array((int) $p, $lots, true)) break;
            $lots[] = $cur = (int) $p;
        }
        $wos = DB::table('stock_txn_line as tl')->join('stock_txn as t', 't.stock_txn_idx', '=', 'tl.stock_txn_idx')
            ->where('t.txn_type', 'IN_PRODUCTION')->where('t.status', '!=', 'CANCELED')->where('tl.line_status', '!=', 'CANCELED')
            ->whereIn('tl.lot_idx', $lots)->whereNotNull('tl.work_order_idx')->pluck('tl.work_order_idx')
            ->merge(DB::table('work_order')->whereIn('lot_idx', $lots)->pluck('work_order_idx'))->unique()->values();
        $soLineIdxs = DB::table('work_order_source')->whereIn('work_order_idx', $wos->all() ?: [0])->pluck('sales_order_line_idx')->unique()->values();
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,partner_idx,status', 'order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,spec,material,spec_unit'])
            ->whereIn('sales_order_line_idx', $soLineIdxs->all() ?: [0])
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->orderBy('due_date')->orderBy('sales_order_line_idx')->get();
        // 그 LOT 의 품목 ( 수주가 없을 때 「수주 없이」 줄로 )
        $item = DB::table('lot as l')->join('item as i', 'i.item_idx', '=', 'l.item_idx')->where('l.lot_idx', $v['lot_idx'])
            ->first(['i.item_idx', 'i.item_code', 'i.item_name', 'i.spec', 'i.spec_unit', 'i.std_sale_price', 'i.is_sale']);
        return response()->json([
            'lines'      => $this->soLineRows($lines, $v['shipment_idx'] ?? null),
            'had_orders' => $soLineIdxs->isNotEmpty(),
            'item'       => $item ? (array) $item : null,
        ]);
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
            'manager_name'                  => 'nullable|string|max:50',
            'site_idx'                      => ['nullable', 'integer', Rule::exists('partner_site', 'site_idx')->where('partner_idx', (int) $request->input('partner_idx'))],
            'ship_address'                  => 'nullable|string|max:200',
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
            'partner_idx' => '고객사', 'manager_name' => '출하 담당자', 'site_idx' => '출고지', 'ship_address' => '주소', 'plan_date' => '출하 예정일', 'lines' => '출하 줄', 'lines.*.item_idx' => '품목', 'lines.*.qty' => '출하 수량', 'lines.*.unit_price' => '단가',
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

    // 날짜만 고치기 ( 출하 완료 건의 [수정] · 11-25 ) — 출하 일시 shipped_at( 'Y-m-d H:i' · 출고 원장도 함께 ) · 출하 예정일 plan_date
    public function editDate(Request $request) {
        $warnings = $this->shipments->editDates($this->find($request), $request->only(['shipped_at', 'plan_date']));
        return response()->json(['message' => '날짜를 고쳤습니다.', 'warnings' => $warnings]);
    }

    // 취소 : 체크한 건 ( 대기 = 상태만 · 완료 = 역분개 )
    public function cancel(Request $request) {
        $v = $request->validate(['shipment_idxs' => 'required|array|min:1|max:100', 'shipment_idxs.*' => 'integer']);
        $n = 0;
        // 하나라도 막히면 전부 되돌린다 ( 입고 취소와 같게 — 전에는 앞 건만 취소된 채 화면엔 실패로 보였다 )
        DB::transaction(function () use ($v, &$n) {
            foreach (Shipment::whereIn('shipment_idx', $v['shipment_idxs'])->orderBy('shipment_idx')->get() as $s) {
                $this->shipments->cancel($s);
                $n++;
            }
        });
        return response()->json(['message' => 'Success', 'canceled' => $n]);
    }

    // ---- 내부 ----

    // 출하지시서 인쇄 데이터 ( 11-18 ) : 출하마다 한 부 + 로고 · 직인 + 문서 양식의 기본 양식 ( 없으면 null — 화면의 처음 배치 ) — 화면 DocPreview
    public function orderSheets(Request $request, \MesCore\Shipment\Services\ShipmentOrderSheetService $sheets, \MesCore\Config\Services\SiteConfigService $site) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $docs = Shipment::with($sheets::RELATIONS)->whereIn('shipment_idx', $v['ids'])->orderBy('plan_date')->orderBy('shipment_idx')->get()
            ->map(fn (Shipment $s) => $sheets->build($s))->values();
        return response()->json([
            'docs'     => $docs,
            'images'   => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],
            'template' => \MesCore\Config\Models\DocTemplate::forPrint('SHIPMENT_ORDER'),
        ]);
    }

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
            'item_spec'      => $l->item?->spec,
            'item_material'  => $l->item?->material,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($l->item),
            'item_short'     => \MesCore\Config\Models\Item::labelOf($l->item, true),   // 머리 행 요약용
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

<?php

namespace MesCore\Label\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Label\Models\BarcodeLabel;
use MesCore\Label\Services\LabelService;
use MesCore\Menu\Services\MenuPermissionService;

// 바코드 라벨 ( LBL-02 발행 · LBL-03 발행이력 ) — docs/screens/00-화면목록.md
//   발행은 여러 화면에서 연다 : LOT = 입고관리 · 재고관리 · 제품재고 / 출하 줄 = 출하관리 ( 그 화면 쓰기 권한 ) · 발행이력 화면은 쓰기로 재인쇄
//   기능 스위치 feature.barcode 가 꺼지면 전부 막힌다
class LabelController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    // 대상별로 발행 · 인쇄를 열 수 있는 화면 ( 하나라도 쓰기 권한이 있으면 )
    private const ISSUE_PAGES = [
        BarcodeLabel::TARGET_LOT      => ['Purchase/ReceiptManage', 'Stock/StockStatus', 'Product/ProductStock', 'Label/LabelHistory'],
        BarcodeLabel::TARGET_SHIPMENT => ['Shipment/ShipmentManage', 'Label/LabelHistory'],
    ];
    private const HISTORY_PAGE = 'Label/LabelHistory';
    private const SORTABLE     = ['create_datetime', 'barcode_no', 'last_printed_at'];

    public function __construct(private LabelService $labels, private MenuPermissionService $permissions) {}

    protected function listTieBreaker(): ?string {
        return 'label_idx';
    }

    // 화면이 버튼을 그릴지 — 기능 스위치 · 대상별 발행 권한 · 양식
    public function access() {
        $on = $this->labels->enabled();
        return response()->json([
            'enabled'      => $on,
            'can_lot'      => $on && $this->canIssue(BarcodeLabel::TARGET_LOT),
            'can_shipment' => $on && $this->canIssue(BarcodeLabel::TARGET_SHIPMENT),
        ]);
    }

    // 발행 창 기본값 ( 번호 · 장수 · 라벨당 수량 · 이미 발행한 장수 ) + 양식
    public function draft(Request $request) {
        $v = $request->validate([
            'target'        => 'required|in:LOT,SHIPMENT_LINE',
            'items'         => 'required|array|min:1|max:200',
            'items.*.id'    => 'required|integer',
            'items.*.qty'   => 'nullable|numeric',
        ]);
        $this->guard($v['target']);

        $isLot = $v['target'] === BarcodeLabel::TARGET_LOT;
        $rows  = $isLot
            ? $this->labels->draftLots(array_map(fn ($r) => ['lot_idx' => $r['id'], 'qty' => $r['qty'] ?? null], $v['items']))
            : $this->labels->draftShipmentLines(array_column($v['items'], 'id'));

        return response()->json([
            'rows'      => $rows,
            'templates' => $this->labels->templates($isLot ? 'LOT' : 'SHIPMENT'),
        ]);
    }

    // 발행 → 인쇄용 데이터를 바로 돌려준다 ( 화면은 미리보기를 연다 · [인쇄] 를 눌러야 인쇄 기록 )
    public function issue(Request $request) {
        $v = $request->validate([
            'target'               => 'required|in:LOT,SHIPMENT_LINE',
            'label_template_idx'   => 'required|integer',
            'rows'                 => 'required|array|min:1|max:200',
            'rows.*.id'            => 'required|integer|distinct',
            'rows.*.barcode_no'    => 'nullable|string|max:60',
            'rows.*.label_count'   => 'required|integer|min:1|max:999',
            'rows.*.label_qty'     => 'nullable|numeric|min:0',
        ], [], ['rows.*.label_count' => '장수', 'rows.*.label_qty' => '라벨당 수량', 'rows.*.barcode_no' => '바코드 번호']);
        $this->guard($v['target']);

        $idxs = $this->labels->issue($v['target'], (int) $v['label_template_idx'], $v['rows']);

        return response()->json(['labels' => $this->labels->sheets($idxs)]);
    }

    // 재인쇄용 데이터 ( 발행이력 )
    public function sheets(Request $request) {
        $v = $request->validate(['label_idxs' => 'required|array|min:1|max:200', 'label_idxs.*' => 'integer']);
        $this->guardHistory('can_read');

        return response()->json(['labels' => $this->labels->sheets($v['label_idxs'])]);
    }

    // 인쇄 기록 — 미리보기의 [인쇄]
    public function printed(Request $request) {
        $v = $request->validate(['label_idxs' => 'required|array|min:1|max:200', 'label_idxs.*' => 'integer']);
        $targets = BarcodeLabel::whereIn('label_idx', $v['label_idxs'])->distinct()->pluck('target');
        foreach ($targets as $t) {
            $this->guard($t);
        }

        return response()->json(['count' => $this->labels->printed($v['label_idxs'])]);
    }

    // 바코드 조회 — 스캔한 번호가 쓸 수 있는지 ( 폐기 라벨이면 알림 ) · 로그인 사용자 누구나 ( 스캔은 여러 화면에서 )
    public function lookup(Request $request) {
        $v = $request->validate(['barcode_no' => 'required|string|max:60']);
        abort_unless($this->labels->enabled(), 404);

        return response()->json($this->labels->lookup(trim($v['barcode_no'])));
    }

    // 발행이력 목록 — 발행일 기간 · 대상 · 상태 · 인쇄 여부 · 검색어
    public function list(Request $request) {
        $v = $request->validate([
            'from'    => 'nullable|date_format:Y-m-d',
            'to'      => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'target'  => 'nullable|in:LOT,SHIPMENT_LINE',
            'status'  => 'nullable|in:ISSUED,SCRAPPED',
            'printed' => 'nullable|in:Y,N',
            'keyword' => 'nullable|string|max:100',
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $this->guardHistory('can_read');

        $query = BarcodeLabel::query()->with([
            'template:label_template_idx,template_name',
            'lot:lot_idx,lot_no,item_idx', 'lot.item:item_idx,item_code,item_name,spec',
            'shipmentLine:shipment_line_idx,shipment_idx,item_idx,line_no', 'shipmentLine.item:item_idx,item_code,item_name,spec',
            'shipmentLine.shipment:shipment_idx,shipment_no,partner_idx', 'shipmentLine.shipment.partner:partner_idx,partner_name',
        ])->orderByDesc('create_datetime')->orderByDesc('label_idx');

        if (!empty($v['from']))   $query->where('create_datetime', '>=', $v['from']);
        if (!empty($v['to']))     $query->where('create_datetime', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['target'])) $query->where('target', $v['target']);
        if (!empty($v['status'])) $query->where('status', $v['status']);
        if (($v['printed'] ?? '') === 'Y') $query->where('print_total', '>', 0);
        if (($v['printed'] ?? '') === 'N') $query->where('print_total', 0);

        $itemOf = fn (Builder $q, string $kw, string $col) => $q->where(fn ($w) => $w
            ->whereHas('lot.item', fn ($r) => $r->where($col, 'like', $kw))
            ->orWhereHas('shipmentLine.item', fn ($r) => $r->where($col, 'like', $kw)));
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'barcode_no'  => fn ($q, $kw) => $q->where('barcode_no', 'like', $kw),
            'item_code'   => fn ($q, $kw) => $itemOf($q, $kw, 'item_code'),
            'item_name'   => fn ($q, $kw) => $itemOf($q, $kw, 'item_name'),
            'shipment_no' => fn ($q, $kw) => $q->whereHas('shipmentLine.shipment', fn ($r) => $r->where('shipment_no', 'like', $kw)),
        ]);

        return $this->listResponse($request, $query, self::SORTABLE, 'labels', function (BarcodeLabel $b) {
            $item = $b->target === BarcodeLabel::TARGET_LOT ? $b->lot?->item : $b->shipmentLine?->item;
            return [
                'label_idx'       => $b->label_idx,
                'target'          => $b->target,
                'barcode_no'      => $b->barcode_no,
                'item_code'       => $item?->item_code,
                'item_name'       => $item?->item_name,
                'item_spec'       => $item?->spec,
                'ref_no'          => $b->target === BarcodeLabel::TARGET_LOT ? $b->lot?->lot_no : $b->shipmentLine?->shipment?->shipment_no,
                'partner_name'    => $b->shipmentLine?->shipment?->partner?->partner_name,
                'template_name'   => $b->template?->template_name,
                'label_count'     => $b->label_count,
                'label_qty'       => $b->label_qty,
                'print_total'     => $b->print_total,
                'last_printed_at' => $b->last_printed_at?->format('Y-m-d H:i'),
                'status'          => $b->status,
                'scrap_reason'    => $b->scrap_reason,
                'create_datetime' => $b->create_datetime?->format('Y-m-d H:i'),
            ];
        });
    }

    // 폐기 — 발행이력 삭제 권한 · 사유 필수
    public function scrap(Request $request) {
        $v = $request->validate([
            'label_idxs'   => 'required|array|min:1|max:200',
            'label_idxs.*' => 'integer',
            'reason'       => 'required|string|max:200',
        ], [], ['reason' => '폐기 사유']);
        $this->guardHistory('can_delete');

        return response()->json(['count' => $this->labels->scrap($v['label_idxs'], $v['reason'])]);
    }

    private function canIssue(string $target): bool {
        return Auth::user() && $this->permissions->canPage(Auth::user(), self::ISSUE_PAGES[$target], 'can_write');
    }

    private function guard(string $target): void {
        abort_unless($this->labels->enabled(), 404);
        abort_unless($this->canIssue($target), 403);
    }

    private function guardHistory(string $action): void {
        abort_unless($this->labels->enabled(), 404);
        abort_unless(Auth::user() && $this->permissions->canPage(Auth::user(), self::HISTORY_PAGE, $action), 403);
    }
}

<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Models\Partner;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Quality\Services\DefectService;
use MesCore\Quality\Services\ShippingInspectionService;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Shipment\Services\ShipmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 출고검사 ( 6003 · QUA-03 ) — docs/screens/70-품질.md §2
//   검사 창은 출하 창( 7001 · 7002 )에서도 연다 → 권한은 컨트롤러가 : 보기 = 출고검사 · 출하관리 · 출하이력 중 하나, 판정 · 취소 = 출고검사 쓰기
class ShippingInspectionController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const READ_SCREENS  = ['Quality/ShippingInspection', 'Shipment/ShipmentManage', 'Shipment/ShipmentHistory'];
    private const WRITE_SCREENS = ['Quality/ShippingInspection'];
    private const SORTABLE      = ['plan_date', 'shipment_no', 'inspected_at'];

    public function __construct(
        private ShippingInspectionService $inspections,
        private ShipmentService $shipments,
        private DefectService $defects,
        private MenuPermissionService $permissions,
    ) {}

    protected function listTieBreaker(): ?string {
        return 'shipment_line.shipment_line_idx';
    }

    // 목록 : TODO( 검사할 줄 — 기본 ) · FAIL( 불합격 · 출하 전 ) · PASS( 합격 ) · ALL
    public function list(Request $request) {
        $this->requireScreen(self::READ_SCREENS, 'can_read');
        $v = $request->validate([
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(['TODO', 'FAIL', 'PASS'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $status = $v['status'] ?? '';   // 없으면 전체 ( 화면 기본값은 blankFilters 가 늘 보낸다 — 스타일 가이드 §3 )

        $query = ShipmentLine::query()
            ->select('shipment_line.*', 'shipment.shipment_no', 'shipment.plan_date', 'shipment.status as shipment_status', 'shipment.partner_idx')
            ->join('shipment', 'shipment.shipment_idx', '=', 'shipment_line.shipment_idx')
            ->addSelect(['partner_name' => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'shipment.partner_idx')])
            ->where('shipment.status', '!=', 'CANCELED')
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'picks.lot:lot_idx,lot_no'])
            ->orderBy('shipment.plan_date')->orderBy('shipment.shipment_idx')->orderBy('shipment_line.line_no');

        $waitOnly = fn (Builder $q) => $q->where('shipment.status', 'WAIT')
            ->when($this->shipments->approvalRequired(), fn ($w) => $w->where('shipment.approval_status', 'APPROVED'));
        match ($status) {
            'TODO' => $waitOnly($query)->where(fn ($w) => $w->where('shipment_line.inspect_status', 'WAIT')
                ->orWhere(fn ($n) => $n->where('shipment_line.inspect_status', 'NONE')->whereIn('shipment_line.item_idx', $this->noneButRequired()))),
            'FAIL' => $waitOnly($query)->where('shipment_line.inspect_status', 'FAIL'),
            'PASS' => $query->where('shipment_line.inspect_status', 'PASS'),
            default => $query->where(fn ($w) => $w->where('shipment_line.inspect_status', '!=', 'NONE')
                ->orWhere(fn ($n) => $n->where('shipment.status', 'WAIT')->whereIn('shipment_line.item_idx', $this->noneButRequired()))),
        };
        if (!empty($v['from']))        $query->where('shipment.plan_date', '>=', $v['from']);
        if (!empty($v['to']))          $query->where('shipment.plan_date', '<=', $v['to']);
        if (!empty($v['partner_idx'])) $query->where('shipment.partner_idx', $v['partner_idx']);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'shipment_no' => fn ($q, $kw) => $q->where('shipment.shipment_no', 'like', $kw),
            'item_code'   => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name'   => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'lot_no'      => fn ($q, $kw) => $q->whereHas('picks.lot', fn ($r) => $r->where('lot_no', 'like', $kw)),
        ]);

        $users = User::pluck('user_name', 'account_idx');
        return $this->listResponse($request, $query, self::SORTABLE, 'lines', fn (ShipmentLine $l) => [
            'shipment_line_idx' => $l->shipment_line_idx,
            'shipment_idx'      => $l->shipment_idx,
            'shipment_no'       => $l->shipment_no,
            'line_label'        => "{$l->shipment_no} - {$l->line_no}",
            'plan_date'         => substr((string) $l->plan_date, 0, 10),
            'partner_name'      => $l->partner_name,
            'item_code'         => $l->item?->item_code,
            'item_name'         => $l->item?->item_name,
            'item_spec'         => $l->item?->spec,
            'item_material'     => $l->item?->material,
            'spec_unit'         => $l->item?->spec_unit,
            'qty'               => (float) $l->qty,
            'lot_summary'       => $l->picks->map(fn ($p) => $p->lot?->lot_no)->filter()->unique()->implode(', '),
            'inspect_status'    => $l->inspect_status === 'NONE' ? 'WAIT' : $l->inspect_status,
            'inspected_at'      => $l->inspected_at?->format('Y-m-d H:i'),
            'inspector_name'    => $l->inspected_by ? ($users[$l->inspected_by] ?? '') : '',
            'inspect_remark'    => $l->inspect_remark,
            'shipment_status'   => $l->shipment_status,
        ]);
    }

    public function formData() {
        $this->requireScreen(self::READ_SCREENS, 'can_read');
        return response()->json([
            'partners' => Partner::whereIn('partner_idx', Shipment::select('partner_idx')->distinct())->orderBy('partner_name')
                ->get(['partner_idx', 'partner_name'])->map(fn ($p) => ['value' => $p->partner_idx, 'label' => $p->partner_name])->values(),
        ]);
    }

    // 검사 창 : 줄 · LOT · 출고 기준서 요약 · 지금 판정 기록 · 검사할 수 있는지
    public function show(int $lineIdx) {
        $this->requireScreen(self::READ_SCREENS, 'can_read');
        $line = $this->findLine($lineIdx);
        $spec = InspectionSpec::withCount('lines')
            ->where('item_idx', $line->item_idx)->where('insp_type', 'SHIPPING')->where('is_use', 'Y')
            ->first(['inspection_spec_idx', 'inspection_spec_name']);
        $inspection = $this->inspections->activeInspection($line);
        $defect     = $inspection?->defects->first();

        return response()->json([
            'line' => [
                'shipment_line_idx' => $line->shipment_line_idx,
                'shipment_idx'      => $line->shipment_idx,
                'line_label'        => "{$line->shipment->shipment_no} - {$line->line_no}",
                'plan_date'         => $line->shipment->plan_date?->toDateString(),
                'partner_name'      => $line->shipment->partner?->partner_name,
                'item_code'         => $line->item?->item_code,
                'item_name'         => $line->item?->item_name,
                'item_spec'         => $line->item?->spec,
                'item_material'     => $line->item?->material,
                'spec_unit'         => $line->item?->spec_unit,
                'qty'               => (float) $line->qty,
                'order_label'       => $line->salesOrderLine?->order ? "{$line->salesOrderLine->order->order_no} #{$line->salesOrderLine->line_no}" : null,
                'lots'              => $line->picks->map(fn ($p) => ['lot_no' => $p->lot?->lot_no, 'warehouse_name' => $p->warehouse?->warehouse_name, 'qty' => (float) $p->qty])->values(),
                'inspect_status'    => $line->inspect_status === 'NONE' ? 'WAIT' : $line->inspect_status,
                'shipment_status'   => $line->shipment->status,
            ],
            'blocked' => in_array($line->inspect_status, ['PASS', 'FAIL'], true) ? null : $this->inspections->inspectable($line),
            'spec' => $spec ? [
                'inspection_spec_name' => $spec->inspection_spec_name,
                'item_count'           => $spec->lines_count,
                'items'                => $spec->lines()->orderBy('seq')->limit(20)->pluck('inspection_item_name'),
            ] : null,
            'inspection' => $inspection ? [
                'result'         => $inspection->result,
                'pass_qty'       => (float) $inspection->pass_qty,
                'fail_qty'       => (float) $inspection->fail_qty,
                'inspected_at'   => $inspection->inspected_at?->format('Y-m-d H:i'),
                'inspector_name' => $inspection->inspector?->user_name ?: $inspection->inspector?->user_id,
                'defect_type'    => $this->defects->typeName($defect?->defect_type_code),
                'reason'         => $defect?->reason,
                'remark'         => $inspection->remark,
            ] : null,
            'can_write'  => $this->can(self::WRITE_SCREENS, 'can_write'),
            'inspectors' => User::where('is_use', 'Y')->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'now'        => now()->format('Y-m-d\TH:i'),
            'me'         => Auth::id(),
        ]);
    }

    public function complete(Request $request, int $lineIdx) {
        $this->requireScreen(self::WRITE_SCREENS, 'can_write');
        $line = $this->findLine($lineIdx);
        $data = $request->validate([
            'result'           => ['required', Rule::in(['PASS', 'FAIL'])],
            'fail_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'           => 'nullable|string|max:200',
            'inspector_idx'    => 'required|integer|exists:account_member,account_idx',
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
        ], [], [
            'result' => '판정', 'fail_qty' => '불량 수량', 'defect_type_code' => '불량 유형', 'reason' => '불량 상세',
            'inspector_idx' => '검사자', 'inspected_at' => '검사일시',
        ]);

        try {
            $inspection = $this->inspections->complete($line, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('출고검사 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
        return response()->json(['message' => 'Success', 'result' => $inspection->result]);
    }

    public function cancel(int $lineIdx) {
        $this->requireScreen(self::WRITE_SCREENS, 'can_write');
        $this->inspections->cancel($this->findLine($lineIdx));
        return response()->json(['message' => 'Success']);
    }

    // ---- 내부 ----

    // 검사 정책이 켜졌는데 저장 때 꺼져 있어 NONE 으로 남은 줄의 품목 ( 출하대기만 — 적은 수 )
    private function noneButRequired(): array {
        return ShipmentLine::query()->join('shipment', 'shipment.shipment_idx', '=', 'shipment_line.shipment_idx')
            ->where('shipment.status', 'WAIT')->where('shipment_line.inspect_status', 'NONE')
            ->distinct()->pluck('shipment_line.item_idx')
            ->filter(fn ($itemIdx) => $this->shipments->inspectRequired((int) $itemIdx))->values()->all() ?: [0];
    }

    private function findLine(int $lineIdx): ShipmentLine {
        $line = ShipmentLine::with(['shipment.partner', 'item', 'picks.lot', 'picks.warehouse', 'salesOrderLine.order'])->find($lineIdx);
        if (!$line || $line->shipment?->status === 'CANCELED') {
            abort(404, '검사할 출하 줄을 찾을 수 없습니다.');
        }
        return $line;
    }

    private function can(array $screens, string $action): bool {
        /** @var User|null $user */
        $user = Auth::user();
        return $user !== null && $this->permissions->canPage($user, $screens, $action);
    }

    private function requireScreen(array $screens, string $action): void {
        if (!$this->can($screens, $action)) {
            abort(403, 'Permission Denied.');
        }
    }
}

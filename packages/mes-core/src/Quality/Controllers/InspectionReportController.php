<?php

namespace MesCore\Quality\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\DocTemplate;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Services\SiteConfigService;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Services\OpResultService;
use MesCore\Quality\Models\Inspection;
use MesCore\Quality\Services\InspectionReportService;
use MesCore\Quality\Services\ProcessInspectionService;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 검사성적서 보기 · 인쇄 ( 11-23 · docs/검사성적서-설계.md §6 ) — 저장된 검사 기록( mode = SHEET ) 기준
 *   show   : 한 건의 측정 기록 ( 공정검사 이력의 [성적서] )
 *   sheets : 인쇄 데이터 ( 문서 양식 INSPECTION_REPORT — DocPreview )
 *   권한 : 그 검사를 여는 화면 어느 쪽이든 읽기 ( 입고 = 입고검사 · 입고관리 / 공정 = 공정검사 · 공정실적 / 출하 = 출고검사 · 출하관리 · 출하이력 )
 */
class InspectionReportController extends Controller {

    private const SCREENS = [
        'INCOMING' => ['Quality/IncomingInspection', 'Purchase/ReceiptManage'],
        'PROCESS'  => ['Quality/ProcessInspection', 'Production/OpResult'],
        'SHIPPING' => ['Quality/ShippingInspection', 'Shipment/ShipmentManage', 'Shipment/ShipmentHistory'],
    ];
    private const TYPE_NAMES = ['INCOMING' => '입고검사', 'PROCESS' => '공정검사', 'IFL_FIRST' => '공정검사 ( 초물 )', 'IFL_MIDDLE' => '공정검사 ( 중물 )', 'IFL_LAST' => '공정검사 ( 종물 )', 'SHIPPING' => '출하검사'];
    private const RESULT_NAMES = ['PASS' => '합격', 'FAIL' => '불합격', 'PARTIAL' => '부분 합격'];

    public function __construct(private InspectionReportService $reports, private MenuPermissionService $perms) {}

    public function show(int $inspectionIdx) {
        $i = $this->find($inspectionIdx);
        return response()->json([
            'inspection_idx' => $i->inspection_idx,
            'spec_name'      => $i->inspection_spec_idx ? InspectionSpec::whereKey($i->inspection_spec_idx)->value('inspection_spec_name') : null,
            'measures'       => $this->reports->rows($i),
        ]);
    }

    public function sheets(Request $request, SiteConfigService $site, PolicyService $policy) {
        $v   = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $our = collect(['name', 'biz_no', 'ceo_name', 'address', 'tel', 'fax'])->mapWithKeys(fn ($k) => [$k => $policy->get("company.{$k}")])->all();
        $docs = collect($v['ids'])->unique()->map(fn ($id) => $this->doc($this->find((int) $id), $our))->values();
        return response()->json([
            'docs'     => $docs,
            'images'   => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],
            'template' => DocTemplate::forPrint('INSPECTION_REPORT'),
        ]);
    }

    // ---- 내부 ----

    private function find(int $idx): Inspection {
        $i = Inspection::with(['measures', 'inspector:account_idx,user_id,user_name'])->find($idx);
        if (!$i || $i->mode !== 'SHEET') abort(404, '성적서가 있는 검사 기록이 아닙니다.');
        $user = Auth::user();
        if (!$user instanceof User || !$this->perms->canPage($user, self::SCREENS[InspectionReportService::groupOf($i->insp_type)], 'can_read')) {
            abort(403, 'Permission Denied.');
        }
        return $i;
    }

    // 성적서 한 부 — 머리 · 아래 항목( docDesign FIELDS.INSPECTION_REPORT ) + 표( 측정 줄 )
    private function doc(Inspection $i, array $our): array {
        $ctx  = $this->context($i);
        $num  = fn ($n) => $n === null ? null : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
        $rows = $this->reports->rows($i);
        return [
            'inspection_idx' => $i->inspection_idx,
            'insp_type_name' => self::TYPE_NAMES[$i->insp_type] ?? $i->insp_type,
            'spec_name'      => $i->inspection_spec_idx ? InspectionSpec::whereKey($i->inspection_spec_idx)->value('inspection_spec_name') : null,
            'result_name'    => self::RESULT_NAMES[$i->result] ?? $i->result,
            'pass_qty'       => (float) $i->pass_qty,
            'fail_qty'       => (float) $i->fail_qty,
            'inspected_at'   => $i->inspected_at?->format('Y-m-d H:i'),
            'inspector_name' => $i->inspector?->user_name ?: $i->inspector?->user_id,
            'remark'         => $i->remark,
            'ng_count'       => collect($rows)->where('judge', 'NG')->count(),
            ...$ctx,
            'supplier'       => $our,
            'receiver'       => ['name' => $ctx['partner_name'] ?? null],
            'lines'          => collect($rows)->values()->map(fn ($r, $k) => [
                'no'                   => $k + 1,
                'inspection_item_name' => $r['inspection_item_name'],
                'nature'               => $r['is_quantitative'] === 'Y' ? '정량' : '정성',
                'method'               => $r['method'],
                'standard_value'       => $r['standard_value'],
                'lower_limit'          => $num($r['lower_limit']),
                'upper_limit'          => $num($r['upper_limit']),
                'unit'                 => $r['unit'],
                'criteria'             => $r['criteria'],
                'measured'             => $r['measured'],
                'judge'                => $r['judge'],
            ])->all(),
        ];
    }

    // 검사 대상 ( 문서 번호 · 품목 · LOT · 거래처 · 검사 수량 )
    private function context(Inspection $i): array {
        $item = fn ($it) => ['item_code' => $it?->item_code, 'item_name' => $it?->item_name, 'item_spec' => $it?->spec, 'spec_unit' => $it?->spec_unit];
        if ($i->ref_type === 'stock_txn_line') {
            $l = StockTxnLine::with(['txn.partner', 'item', 'lot'])->find($i->ref_idx);
            return ['doc_no' => $l?->txn?->txn_no, 'lot_no' => $l?->lot?->lot_no, 'partner_name' => $l?->txn?->partner?->partner_name, 'insp_qty' => (float) ($l?->qty ?? 0)] + $item($l?->item);
        }
        if ($i->ref_type === 'shipment_line') {
            $l = ShipmentLine::with(['shipment.partner', 'item', 'picks.lot'])->find($i->ref_idx);
            return ['doc_no' => $l ? "{$l->shipment?->shipment_no} - {$l->line_no}" : null, 'lot_no' => $l?->picks->pluck('lot.lot_no')->filter()->unique()->implode(', '),
                'partner_name' => $l?->shipment?->partner?->partner_name, 'insp_qty' => (float) ($l?->qty ?? 0)] + $item($l?->item);
        }
        // 공정 ( work_order_op ) — 검사 수량 = 합격 + 불합격 ( 초 · 중 · 종은 그 공정 실적 )
        $op = WorkOrderOp::with('process')->find($i->ref_idx);
        $wo = $op ? WorkOrder::with(['item', 'lot'])->find($op->work_order_idx) : null;
        $isStage = in_array($i->insp_type, ProcessInspectionService::STAGE_TYPES, true);
        return [
            'doc_no'       => $wo ? "{$wo->wo_no} · " . app(OpResultService::class)->opName($op) : null,
            'lot_no'       => $wo?->lot?->lot_no,
            'partner_name' => null,
            'insp_qty'     => $isStage ? (float) ($op?->qty ?? 0) : (float) $i->pass_qty + (float) $i->fail_qty,
        ] + $item($wo?->item);
    }
}

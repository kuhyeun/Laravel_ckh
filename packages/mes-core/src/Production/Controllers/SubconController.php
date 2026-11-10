<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Partner;
use MesCore\Config\Models\Warehouse;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Services\OpResultService;
use MesCore\Production\Services\SubconService;
use MesCore\Quality\Services\DefectService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 외주관리 ( 4005 ) — 외주 출고 400501 · 외주 입고 400502 · 외주 품질검사 400503 · 외주 이력관리 400504 ( SubconService )
//   목록( 출고 · 입고 · 이력 )은 서버 페이징 · 정렬 · 엑셀 전체( RespondsWithList ) — 전에는 기간을 넓히면 전부 내려받았다
class SubconController extends Controller {
    use SearchesKeyword, RespondsWithList;

    const ISSUE_SORTABLE   = ['issue_no', 'issued_at', 'partner_name', 'wo_no', 'item_code', 'process_name', 'qty', 'amount', 'received', 'issuer_name'];
    const RECEIPT_SORTABLE = ['receipt_no', 'received_at', 'done_at', 'issue_no', 'partner_name', 'wo_no', 'item_code', 'process_name', 'r_qty', 'pass_qty', 'fail_qty', 'r_status', 'inspector_name'];
    const HISTORY_SORTABLE = ['at', 'kind', 'doc_no', 'partner_name', 'wo_no', 'item_code', 'process_name', 'move_qty'];

    private ?string $tie = null;   // 열 정렬 때 같은 값끼리의 순서 — 목록마다 다르다

    protected function listTieBreaker(): ?string {
        return $this->tie;
    }

    const CANDIDATE_LIMIT = 500;   // 출고 대상( 끝나지 않은 외주 공정 ) 상한 — 넘으면 검색어로 좁힌다

    public function __construct(private SubconService $subcon) {}

    // ---- 선택지 ----

    public function formData(\MesCore\Menu\Services\MenuPermissionService $permissions) {
        return response()->json([
            'op_result_url'     => $permissions->canPageForMe('Production/OpResult', 'can_read')
                ? \MesCore\Menu\Models\Menu::where('page_component', 'Production/OpResult')->orderByRaw("read_only = 'N' DESC")->value('url_path') : null,
            'partners'          => Partner::where('is_subcon', 'Y')->where('is_use', 'Y')->orderBy('partner_name')->forPicker()->get(),
            'warehouses'        => Warehouse::options(),
            'inspection_on'     => $this->subcon->inspectionOn(),
            'inspectors'        => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'me'                => User::pickableMe(),
            'now'               => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // ---- 외주 출고 ----

    // 출고 대상 : 대기 · 진행 중 지시의 끝나지 않은 외주 공정 ( 출고 가능 = 기준 − 이미 출고 )
    public function candidates(Request $request, OpResultService $results) {
        $v = $request->validate(['keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30']);
        $q = WorkOrderOp::query()->from('work_order_op as op')
            ->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->join('work_order as wo', 'wo.work_order_idx', '=', 'op.work_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'wo.item_idx')
            ->where('p.process_type', 'SUBCON')->whereIn('wo.status', ['WAIT', 'RUN'])->where('op.status', '!=', 'DONE');
        $this->keyword($q, $v);
        $opIdxs = $q->orderBy('wo.wo_no')->orderBy('op.seq')->limit(self::CANDIDATE_LIMIT)->pluck('op.work_order_op_idx');

        // 지시 · 공정 · 출고 합계를 한꺼번에 읽는다 ( 전에는 지시마다 · 공정마다 따로 읽었다 )
        $mineByWo = WorkOrderOp::whereIn('work_order_op_idx', $opIdxs)->get(['work_order_op_idx', 'work_order_idx'])->groupBy('work_order_idx');
        $woIds    = $mineByWo->keys()->all();
        $wos      = WorkOrder::with('item')->whereIn('work_order_idx', $woIds)->get()->keyBy('work_order_idx');
        $opsByWo  = WorkOrderOp::with('process')->whereIn('work_order_idx', $woIds)->orderBy('seq')->get()->groupBy('work_order_idx');
        $issuedBy = DB::table('subcon_issue')->whereIn('work_order_op_idx', $opIdxs)->where('is_canceled', 'N')
            ->groupBy('work_order_op_idx')->selectRaw('work_order_op_idx, SUM(qty) as n')->pluck('n', 'work_order_op_idx');

        $rows = [];
        foreach ($mineByWo as $woIdx => $mine) {
            $wo  = $wos[$woIdx];
            $ops = $opsByWo[$woIdx];
            foreach ($mine as $o) {
                $op = $ops->firstWhere('work_order_op_idx', $o->work_order_op_idx);
                $av = $this->subcon->available($wo, $ops, $op, (float) ($issuedBy[$op->work_order_op_idx] ?? 0));
                $prev = $ops->filter(fn ($x) => $x->seq < $op->seq && ($x->isQtyOp() || $x->isInspectionOp()))->last();
                $rows[] = [
                    'work_order_op_idx' => $op->work_order_op_idx,
                    'work_order_idx'    => $wo->work_order_idx,
                    'wo_no'             => $wo->wo_no,
                    'wo_status'         => $wo->status,
                    'item_code'         => $wo->item?->item_code,
                    'item_name'         => $wo->item?->item_name,
                    'item_spec'         => $wo->item?->spec,
                    'spec_unit'         => $wo->item?->spec_unit,
                    'process_name'      => $op->process?->process_name,
                    'prev_label'        => $prev ? $prev->process?->process_name : '지시 수량',
                    'target_qty'        => (float) $wo->target_qty,
                    'base_qty'          => $av['base'],
                    'issued_qty'        => $av['issued'],
                    'available_qty'     => $av['available'],
                    'is_last'           => $results->receivesOnRun($ops, $op),
                ];
            }
        }
        return response()->json(['rows' => $rows]);
    }

    public function issue(Request $request) {
        $d = $request->validate([
            'work_order_op_idx' => 'required|integer',
            'partner_idx'       => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'amount'            => 'nullable|numeric|min:0|max:9999999999999',
            'issued_at'         => 'required|date_format:Y-m-d\TH:i',
            'remark'            => 'nullable|string|max:200',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.'], ['partner_idx' => '외주처', 'qty' => '수량', 'amount' => '외주 금액', 'issued_at' => '출고 일시']);
        $issue = $this->subcon->issue($d);
        return response()->json(['message' => 'Success', 'issue_no' => $issue->issue_no]);
    }

    public function cancelIssue(Request $request) {
        $d = $request->validate(['subcon_issue_idx' => 'required|integer']);
        $this->subcon->cancelIssue((int) $d['subcon_issue_idx']);
        return response()->json(['message' => 'Success']);
    }

    // 출고 목록 ( 출고 화면 · 입고 화면 공용 ) — open=1 이면 입고할 수량이 남은 것만
    public function issues(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
            'partner_idx' => 'nullable|integer', 'open' => 'nullable|boolean', 'include_canceled' => 'nullable|boolean',
        ]);
        $q = $this->issueBase()
            ->leftJoinSub(DB::table('subcon_receipt')->where('status', '!=', 'CANCELED')->groupBy('subcon_issue_idx')
                ->selectRaw('subcon_issue_idx, SUM(qty) as received, SUM(COALESCE(pass_qty,0)) as passed, SUM(COALESCE(fail_qty,0)) as failed, SUM(status = "WAIT") as waiting'), 'rc', 'rc.subcon_issue_idx', '=', 's.subcon_issue_idx')
            ->addSelect(DB::raw('COALESCE(rc.received,0) as received'), DB::raw('COALESCE(rc.passed,0) as passed'), DB::raw('COALESCE(rc.failed,0) as failed'), DB::raw('COALESCE(rc.waiting,0) as waiting'));
        if (!filter_var($v['include_canceled'] ?? false, FILTER_VALIDATE_BOOLEAN)) $q->where('s.is_canceled', 'N');
        if (filter_var($v['open'] ?? false, FILTER_VALIDATE_BOOLEAN)) $q->where('s.is_canceled', 'N')->whereRaw('s.qty - COALESCE(rc.received,0) > 0.000001');
        $this->period($q, 's.issued_at', $v);
        if (!empty($v['partner_idx'])) $q->where('s.partner_idx', $v['partner_idx']);
        $this->keyword($q, $v);

        // 마지막 공정( 제품 입고 — 입고 때 창고 필요 ) — 그 페이지 지시만 한 번에
        $q->orderBy('s.issued_at')->orderBy('s.subcon_issue_idx');
        $this->tie = 's.subcon_issue_idx';
        $lastOps = [];
        return $this->listResponse($request, $q, self::ISSUE_SORTABLE, 'rows', fn ($r) => $this->issueRow($r) + [
            'received_qty' => (float) $r->received,
            'passed_qty'   => (float) $r->passed,
            'failed_qty'   => (float) $r->failed,
            'waiting'      => (int) $r->waiting,
            'left_qty'     => $r->is_canceled === 'Y' ? 0.0 : max(0, (float) $r->qty - (float) $r->received),
            'is_last'      => ($lastOps[$r->work_order_idx] ?? null) === (int) $r->work_order_op_idx,
        ], function ($rows) use (&$lastOps) { $lastOps = $this->lastOps($rows->pluck('work_order_idx')->unique()->all()); });
    }

    // ---- 외주 입고 ----

    public function receive(Request $request) {
        $d = $request->validate([
            'subcon_issue_idx' => 'required|integer',
            'qty'              => 'required|numeric|gt:0|max:999999999999',
            'received_at'      => 'required|date_format:Y-m-d\TH:i',
            'warehouse_idx'    => 'nullable|integer',
            'remark'           => 'nullable|string|max:200',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.'], ['qty' => '입고 수량', 'received_at' => '입고 일시', 'warehouse_idx' => '창고']);
        $rc = $this->subcon->receive($d);
        return response()->json(['message' => 'Success', 'receipt_no' => $rc->receipt_no, 'status' => $rc->status]);
    }

    public function cancelReceipt(Request $request) {
        $d = $request->validate(['subcon_receipt_idx' => 'required|integer']);
        $this->subcon->cancelReceipt((int) $d['subcon_receipt_idx']);
        return response()->json(['message' => 'Success']);
    }

    // 입고 목록 ( 입고 화면 · 품질검사 화면 공용 ) — status 거르기
    public function receipts(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
            'partner_idx' => 'nullable|integer', 'status' => ['nullable', Rule::in(array_keys(SubconService::RECEIPT_STATUSES))],
            'hide_canceled' => 'nullable|boolean',   // 상태를 고르지 않았을 때 취소분 빼기 ( 품질검사 화면 — 페이징이라 서버에서 거른다 )
        ]);
        $q = $this->issueBase()->join('subcon_receipt as r', 'r.subcon_issue_idx', '=', 's.subcon_issue_idx')
            ->leftJoin('account_member as iu', 'iu.account_idx', '=', 'r.create_account_idx')
            ->leftJoin('inspection as insp', 'insp.inspection_idx', '=', 'r.inspection_idx')
            ->leftJoin('account_member as qu', 'qu.account_idx', '=', 'insp.inspector_idx')
            ->addSelect('r.subcon_receipt_idx', 'r.receipt_no', 'r.qty as r_qty', 'r.pass_qty', 'r.fail_qty', 'r.status as r_status', 'r.received_at', 'r.done_at', 'r.remark as r_remark',
                DB::raw("COALESCE(NULLIF(qu.user_name, ''), qu.user_id) as inspector_name"));
        if (!empty($v['status'])) $q->where('r.status', $v['status']);
        elseif (filter_var($v['hide_canceled'] ?? false, FILTER_VALIDATE_BOOLEAN)) $q->where('r.status', '!=', 'CANCELED');
        $this->period($q, 'r.received_at', $v);
        if (!empty($v['partner_idx'])) $q->where('s.partner_idx', $v['partner_idx']);
        $this->keyword($q, $v);

        $q->orderBy('r.received_at')->orderBy('r.subcon_receipt_idx');
        $this->tie = 'r.subcon_receipt_idx';
        return $this->listResponse($request, $q, self::RECEIPT_SORTABLE, 'rows', fn ($r) => $this->issueRow($r) + [
            'subcon_receipt_idx' => $r->subcon_receipt_idx,
            'receipt_no'         => $r->receipt_no,
            'receipt_qty'        => (float) $r->r_qty,
            'pass_qty'           => $r->pass_qty !== null ? (float) $r->pass_qty : null,
            'fail_qty'           => $r->fail_qty !== null ? (float) $r->fail_qty : null,
            'status'             => $r->r_status,
            'status_label'       => SubconService::RECEIPT_STATUSES[$r->r_status] ?? $r->r_status,
            'received_at'        => substr((string) $r->received_at, 0, 16),
            'done_at'            => substr((string) $r->done_at, 0, 16),
            'receipt_remark'     => $r->r_remark,
            'inspector_name'     => $r->inspector_name,
        ]);
    }

    // ---- 외주 품질검사 ----

    public function inspect(Request $request, int $receiptIdx) {
        $d = $request->validate([
            'pass_qty'         => 'required|numeric|min:0|max:999999999999',
            'fail_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'           => 'nullable|string|max:200',
            'inspector_idx'    => ['required', 'integer', User::pickableRule()],
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
        ], [], ['pass_qty' => '합격', 'fail_qty' => '불합격', 'defect_type_code' => '불량 유형', 'reason' => '불량 상세', 'inspector_idx' => '검사자', 'inspected_at' => '검사일시']);
        $this->subcon->inspect($receiptIdx, $d);
        return response()->json(['message' => 'Success']);
    }

    // 검사 결과 한 건 ( 검사 창 )
    public function inspection(int $receiptIdx, DefectService $defects) {
        $rc = DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->first();
        $insp = $rc?->inspection_idx ? \MesCore\Quality\Models\Inspection::with(['defects', 'inspector'])->find($rc->inspection_idx) : null;
        return response()->json(['inspection' => $insp ? [
            'result'         => $insp->result,
            'pass_qty'       => (float) $insp->pass_qty,
            'fail_qty'       => (float) $insp->fail_qty,
            'inspected_at'   => $insp->inspected_at?->format('Y-m-d H:i'),
            'inspector_name' => $insp->inspector?->user_name ?: $insp->inspector?->user_id,
            'defect_type'    => $defects->typeName($insp->defects->first()?->defect_type_code),
            'reason'         => $insp->defects->first()?->reason,
            'remark'         => $insp->remark,
        ] : null]);
    }

    // ---- 외주 이력 : 출고 − · 입고 + ( 합격분 · 불합격은 칸으로 ) ----

    public function history(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
            'partner_idx' => 'nullable|integer',
        ]);
        $out = $this->issueBase()->where('s.is_canceled', 'N')
            ->addSelect(DB::raw("'ISSUE' as kind"), 's.issued_at as at', DB::raw('-s.qty as move_qty'), DB::raw('NULL as fail'), 's.issue_no as doc_no');
        $in = $this->issueBase()->join('subcon_receipt as r', 'r.subcon_issue_idx', '=', 's.subcon_issue_idx')->where('r.status', 'DONE')
            ->addSelect(DB::raw("'RECEIPT' as kind"), 'r.done_at as at', DB::raw('r.pass_qty as move_qty'), DB::raw('r.fail_qty as fail'), 'r.receipt_no as doc_no');
        foreach ([$out, $in] as $q) {
            if (!empty($v['partner_idx'])) $q->where('s.partner_idx', $v['partner_idx']);
            $this->keyword($q, $v);
        }
        $this->period($out, 's.issued_at', $v);
        $this->period($in, 'r.done_at', $v);

        // 출고 · 입고를 한 쿼리로 합쳐( UNION ALL ) 서버에서 정렬 · 페이징 ( 전에는 둘 다 전부 읽어 PHP 에서 합쳤다 )
        $q = DB::query()->fromSub($out->unionAll($in), 'h')->select('h.*')->orderBy('h.at')->orderByRaw("h.kind = 'RECEIPT'")->orderBy('h.doc_no');
        $this->tie = 'h.doc_no';
        return $this->listResponse($request, $q, self::HISTORY_SORTABLE, 'rows', fn ($r) => $this->issueRow($r) + [
            'kind'       => $r->kind,
            'kind_label' => $r->kind === 'ISSUE' ? '외주 출고' : '외주 입고',
            'at'         => substr((string) $r->at, 0, 16),
            'move_qty'   => (float) $r->move_qty,
            'fail_qty'   => $r->fail !== null ? (float) $r->fail : null,
            'doc_no'     => $r->doc_no,
        ]);
    }

    // ---- 내부 ----

    // 출고 + 지시 · 공정 · 품목 · 외주처
    private function issueBase(): Builder {
        return DB::table('subcon_issue as s')
            ->join('work_order as wo', 'wo.work_order_idx', '=', 's.work_order_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 's.work_order_op_idx')
            ->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->join('item as i', 'i.item_idx', '=', 'wo.item_idx')
            ->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')
            ->leftJoin('account_member as su', 'su.account_idx', '=', 's.create_account_idx')
            ->select('s.subcon_issue_idx', 's.issue_no', 's.work_order_idx', 's.work_order_op_idx', 's.partner_idx', 's.qty', 's.amount', 's.issued_at', 's.remark', 's.is_canceled',
                'wo.wo_no', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'p.process_name', 'pt.partner_name',
                DB::raw("COALESCE(NULLIF(su.user_name, ''), su.user_id) as issuer_name"));
    }

    private function issueRow(object $r): array {
        return [
            'subcon_issue_idx'  => $r->subcon_issue_idx,
            'issue_no'          => $r->issue_no,
            'work_order_idx'    => $r->work_order_idx,
            'work_order_op_idx' => $r->work_order_op_idx,
            'wo_no'             => $r->wo_no,
            'item_code'         => $r->item_code,
            'item_name'         => $r->item_name,
            'item_spec'         => $r->item_spec,
            'spec_unit'         => $r->spec_unit,
            'process_name'      => $r->process_name,
            'partner_idx'       => $r->partner_idx,
            'partner_name'      => $r->partner_name,
            'qty'               => (float) $r->qty,
            'amount'            => (float) $r->amount,
            'issued_at'         => substr((string) $r->issued_at, 0, 16),
            'remark'            => $r->remark,
            'is_canceled'       => $r->is_canceled === 'Y',
            'issuer_name'       => $r->issuer_name,
        ];
    }

    private function keyword($q, array $v): void {
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('wo.wo_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
        ]);
    }

    private function period($q, string $col, array $v): void {
        if (!empty($v['from'])) $q->where($col, '>=', $v['from']);
        if (!empty($v['to']))   $q->where($col, '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
    }

    /** @return array<int,int> 지시 → 마지막 공정( 수량 공정이면 ) */
    private function lastOps(array $woIdxs): array {
        if (!$woIdxs) return [];
        return WorkOrderOp::with('process')->whereIn('work_order_idx', $woIdxs)->orderBy('seq')->get()->groupBy('work_order_idx')
            ->map(fn ($ops) => $ops->last()?->isQtyOp() ? (int) $ops->last()->work_order_op_idx : 0)->all();
    }
}

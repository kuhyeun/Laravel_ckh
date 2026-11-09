<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Partner;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Quality\Models\Inspection;
use MesCore\Quality\Services\DefectService;

/**
 * 외주관리 ( 4005 · 11-05 사용자 정의 )
 *
 *   외주 출고 : 대기 · 진행 중 작업지시의 외주 공정( 공정 구분 SUBCON )만
 *     출고 가능 = 기준 − 이미 출고 ( 기준 = 앞 수량 공정 실적 · 앞 공정이 없으면 지시 수량 ) — 기준이 0 이면 출고 불가
 *     외주처( 거래처 외주처 구분 ) · 외주 금액( 총 비용 · 입력 그대로 · 기록만 ) · 재공 : 앞 공정 −
 *   외주 입고 : 출고 건마다 남은 수량( 출고 − 입고 )까지 · 여러 번
 *     정책 quality.subcon_inspection = Y → 검사대기 → 외주관리 > 품질검사에서 합격 · 불합격 → 완료 / N → 바로 완료
 *     완료 = 합격분이 외주 공정 실적 ( OpResultService::addRun — 마지막 공정이면 제품 입고 · 재공 + ) · 불합격은 불량 기록만
 *   취소 : 출고 = 입고가 없을 때 / 입고 = 실적 취소( 뒤 공정이 쓰지 않았을 때 ) + 검사 · 불량 취소 표시
 */
class SubconService {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

    public const RECEIPT_STATUSES = ['WAIT' => '검사대기', 'DONE' => '입고 완료', 'CANCELED' => '취소'];
    public const INSP_REF_TYPE = 'subcon_receipt';
    private const EPS = 0.000001;

    public function __construct(
        private OpResultService $results,
        private WipService $wip,
        private DefectService $defects,
        private PolicyService $policy,
    ) {}

    public function inspectionOn(): bool {
        return (bool) $this->policy->get('quality.subcon_inspection');
    }

    // ---- 출고 ----

    /** 출고할 수 있는 수량 ( 기준 − 이미 출고 ) · 기준 */
    //   $issued : 미리 읽어 둔 출고 합계 ( 목록이 한꺼번에 읽어 넘긴다 — 없으면 여기서 읽는다 )
    public function available(WorkOrder $wo, Collection $ops, WorkOrderOp $op, ?float $issued = null): array {
        $base   = (float) ($this->results->baseQty($wo, $ops, $op) ?? 0);
        $issued ??= (float) DB::table('subcon_issue')->where('work_order_op_idx', $op->work_order_op_idx)->where('is_canceled', 'N')->sum('qty');
        return ['base' => $base, 'issued' => $issued, 'available' => max(0, $base - $issued)];
    }

    public function issue(array $d): object {
        return DB::transaction(function () use ($d) {
            [$wo, $ops, $op] = $this->lockOp((int) $d['work_order_op_idx']);
            if (!$op->isSubconOp()) {
                throw ValidationException::withMessages(['work_order_op_idx' => ['외주 공정이 아닙니다.']]);
            }
            if (!in_array($wo->status, ['WAIT', 'RUN'], true) || $op->status === 'DONE') {
                throw ValidationException::withMessages(['work_order_op_idx' => ['대기 · 진행 중인 작업지시의 끝나지 않은 공정만 외주 출고할 수 있습니다.']]);
            }
            if (!Partner::whereKey($d['partner_idx'])->where('is_subcon', 'Y')->where('is_use', 'Y')->exists()) {
                throw ValidationException::withMessages(['partner_idx' => ['사용 중인 외주처를 고르세요.']]);
            }
            $qty = (float) $d['qty'];
            $av  = $this->available($wo, $ops, $op);
            if ($av['base'] <= self::EPS) {
                throw ValidationException::withMessages(['qty' => ['앞 공정 실적이 없습니다. 앞 공정에서 만든 수량이 있어야 외주 출고할 수 있습니다.']]);   // E_SUBCON_NO_BASE
            }
            if ($qty > $av['available'] + self::EPS) {
                throw ValidationException::withMessages(['qty' => ["출고할 수 있는 수량은 {$this->num($av['available'])} 입니다. ( 기준 {$this->num($av['base'])} − 이미 출고 {$this->num($av['issued'])} )"]]);   // E_SUBCON_OVER
            }
            $at    = Carbon::parse($d['issued_at']);
            $idx = DB::table('subcon_issue')->insertGetId([
                'issue_no'           => $this->nextNo('subcon_issue', 'issue_no', 'SC', $at),
                'work_order_idx'     => $wo->work_order_idx,
                'work_order_op_idx'  => $op->work_order_op_idx,
                'partner_idx'        => (int) $d['partner_idx'],
                'qty'                => $this->str($qty),
                'amount'             => round((float) ($d['amount'] ?? 0), 2),   // 외주 총 비용 ( 계산 없이 입력 그대로 )
                'issued_at'          => $at,
                'remark'             => $d['remark'] ?? null,
                'create_account_idx' => Auth::id() ?? 0,
                'create_datetime'    => now(),
            ]);
            $issue = DB::table('subcon_issue')->where('subcon_issue_idx', $idx)->first();
            $this->wip->recordSubconIssue($wo, $ops, $op, $issue);
            return $issue;
        });
    }

    public function cancelIssue(int $issueIdx): void {
        DB::transaction(function () use ($issueIdx) {
            $issue = DB::table('subcon_issue')->where('subcon_issue_idx', $issueIdx)->lockForUpdate()->first();
            if (!$issue || $issue->is_canceled === 'Y') {
                throw ValidationException::withMessages(['subcon_issue_idx' => ['이미 취소되었거나 없는 출고입니다.']]);
            }
            if (DB::table('subcon_receipt')->where('subcon_issue_idx', $issueIdx)->where('status', '!=', 'CANCELED')->exists()) {
                throw ValidationException::withMessages(['subcon_issue_idx' => ["{$issue->issue_no} 은 입고가 있어 취소할 수 없습니다. 입고를 먼저 취소하세요."]]);
            }
            DB::table('subcon_issue')->where('subcon_issue_idx', $issueIdx)->update(['is_canceled' => 'Y', 'canceled_by' => Auth::id(), 'canceled_at' => now(), 'update_account_idx' => Auth::id()]);
            $this->wip->reverseSubconIssue($issue);
        });
    }

    /** 출고 건의 입고 현황 */
    public function issueState(int $issueIdx): array {
        $r = DB::table('subcon_receipt')->where('subcon_issue_idx', $issueIdx)->where('status', '!=', 'CANCELED')
            ->selectRaw('COALESCE(SUM(qty),0) as received, COALESCE(SUM(pass_qty),0) as passed, COALESCE(SUM(fail_qty),0) as failed, SUM(status = "WAIT") as waiting')->first();
        return ['received' => (float) $r->received, 'passed' => (float) $r->passed, 'failed' => (float) $r->failed, 'waiting' => (int) $r->waiting];
    }

    // ---- 입고 ----

    public function receive(array $d): object {
        return DB::transaction(function () use ($d) {
            $issue = DB::table('subcon_issue')->where('subcon_issue_idx', $d['subcon_issue_idx'])->lockForUpdate()->first();
            if (!$issue || $issue->is_canceled === 'Y') {
                throw ValidationException::withMessages(['subcon_issue_idx' => ['취소되었거나 없는 출고입니다.']]);
            }
            $qty  = (float) $d['qty'];
            $left = (float) $issue->qty - $this->issueState($issue->subcon_issue_idx)['received'];
            if ($qty > $left + self::EPS) {
                throw ValidationException::withMessages(['qty' => ["입고할 수 있는 수량은 {$this->num(max(0, $left))} 입니다. ( 출고 {$this->num((float) $issue->qty)} − 이미 입고 )"]]);   // E_SUBCON_RCV_OVER
            }
            [$wo, $ops, $op] = $this->lockOp((int) $issue->work_order_op_idx);
            if ($this->results->receivesOnRun($ops, $op) && empty($d['warehouse_idx'])) {
                throw ValidationException::withMessages(['warehouse_idx' => ['마지막 공정입니다. 제품을 넣을 창고를 고르세요.']]);
            }
            $at  = Carbon::parse($d['received_at']);
            $idx = DB::table('subcon_receipt')->insertGetId([
                'receipt_no'         => $this->nextNo('subcon_receipt', 'receipt_no', 'SR', $at),
                'subcon_issue_idx'   => $issue->subcon_issue_idx,
                'qty'                => $this->str($qty),
                'status'             => 'WAIT',
                'received_at'        => $at,
                'warehouse_idx'      => !empty($d['warehouse_idx']) ? (int) $d['warehouse_idx'] : null,
                'remark'             => $d['remark'] ?? null,
                'create_account_idx' => Auth::id() ?? 0,
                'create_datetime'    => now(),
            ]);
            if (!$this->inspectionOn()) {
                $this->complete($idx, $qty, 0.0, $at, Auth::id());   // 검사 안 씀 → 바로 실적
            }
            return DB::table('subcon_receipt')->where('subcon_receipt_idx', $idx)->first();
        });
    }

    /** 외주 검사 ( 검사대기 → 합격 · 불합격 → 입고 완료 ) */
    public function inspect(int $receiptIdx, array $d): object {
        return DB::transaction(function () use ($receiptIdx, $d) {
            $rc = DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->lockForUpdate()->first();
            if (!$rc || $rc->status !== 'WAIT') {
                throw ValidationException::withMessages(['receipt' => ['검사대기 상태가 아닙니다. 목록을 새로고침하세요.']]);
            }
            $pass = (float) $d['pass_qty'];
            $fail = (float) ($d['fail_qty'] ?? 0);
            if (abs($pass + $fail - (float) $rc->qty) > self::EPS) {
                throw ValidationException::withMessages(['pass_qty' => ["합격과 불합격을 더하면 입고 수량( {$this->num((float) $rc->qty)} )이 되어야 합니다."]]);
            }
            $this->defects->validate($fail, $d['defect_type_code'] ?? null, $d['reason'] ?? null);
            $at = Carbon::parse($d['inspected_at']);
            if ($at->gt(now()->addMinutes(10))) {
                throw ValidationException::withMessages(['inspected_at' => ['아직 오지 않은 시각입니다.']]);
            }
            $issue = DB::table('subcon_issue')->where('subcon_issue_idx', $rc->subcon_issue_idx)->first();
            $wo    = WorkOrder::find($issue->work_order_idx);

            $inspection = Inspection::create([
                'insp_type'     => 'SUBCON',
                'ref_type'      => self::INSP_REF_TYPE,
                'ref_idx'       => $rc->subcon_receipt_idx,
                'mode'          => 'SIMPLE',
                'result'        => $fail <= self::EPS ? 'PASS' : ($pass <= self::EPS ? 'FAIL' : 'PARTIAL'),
                'pass_qty'      => $this->str($pass),
                'fail_qty'      => $this->str($fail),
                'inspected_at'  => $at,
                'inspector_idx' => $d['inspector_idx'],
                'remark'        => $d['remark'] ?? null,
            ]);
            $this->defects->record($inspection, [
                'item_idx'          => $wo->item_idx,
                'lot_idx'           => $wo->lot_idx ?? 0,
                'partner_idx'       => $issue->partner_idx,
                'work_order_op_idx' => $issue->work_order_op_idx,
                'qty'               => $fail,
                'defect_type_code'  => $d['defect_type_code'] ?? null,
                'reason'            => $d['reason'] ?? null,
                'occurred_at'       => $at,
            ]);
            DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->update(['inspection_idx' => $inspection->inspection_idx]);
            $this->complete($receiptIdx, $pass, $fail, $at, (int) $d['inspector_idx']);
            return DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->first();
        });
    }

    // 입고 취소 : 완료면 실적 취소( 뒤 공정이 쓰지 않았을 때 ) · 검사 · 불량 취소 표시
    public function cancelReceipt(int $receiptIdx): void {
        DB::transaction(function () use ($receiptIdx) {
            $rc = DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->lockForUpdate()->first();
            if (!$rc || $rc->status === 'CANCELED') {
                throw ValidationException::withMessages(['receipt' => ['이미 취소되었거나 없는 입고입니다.']]);
            }
            if ($rc->run_idx && ($run = WorkOrderOpRun::find($rc->run_idx)) && $run->is_canceled === 'N') {
                $this->results->cancelRun($run, true);
            }
            if ($rc->inspection_idx && ($insp = Inspection::find($rc->inspection_idx))) {
                $insp->fill(['is_canceled' => 'Y'])->save();
                $this->defects->cancel($insp);
            }
            DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)
                ->update(['status' => 'CANCELED', 'canceled_by' => Auth::id(), 'canceled_at' => now(), 'update_account_idx' => Auth::id()]);
        });
    }

    // ---- 내부 ----

    // 입고 완료 : 합격분 → 외주 공정 실적 ( 0 이면 실적 없음 )
    private function complete(int $receiptIdx, float $pass, float $fail, Carbon $at, ?int $operator): void {
        $rc    = DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->first();
        $issue = DB::table('subcon_issue')->where('subcon_issue_idx', $rc->subcon_issue_idx)->first();
        $runIdx = null;
        if ($pass > self::EPS) {
            $op = WorkOrderOp::find($issue->work_order_op_idx);
            [$run] = $this->results->addRun($op, [
                'equipment_idx' => null,
                'operator_idx'  => $operator ?: (Auth::id() ?? 0),
                'qty'           => $this->str($pass),
                'worked_at'     => $at->format('Y-m-d H:i:s'),
                'remark'        => "외주 입고 {$rc->receipt_no}",
                'warehouse_idx' => $rc->warehouse_idx,
            ], true);
            $runIdx = $run->run_idx;
        }
        DB::table('subcon_receipt')->where('subcon_receipt_idx', $receiptIdx)->update([
            'status' => 'DONE', 'pass_qty' => $this->str($pass), 'fail_qty' => $this->str($fail), 'run_idx' => $runIdx, 'done_at' => $at, 'update_account_idx' => Auth::id(),
        ]);
    }

    /** @return array{0: WorkOrder, 1: Collection, 2: WorkOrderOp} */
    private function lockOp(int $opIdx): array {
        $op = WorkOrderOp::find($opIdx);
        if (!$op) {
            throw ValidationException::withMessages(['work_order_op_idx' => ['없는 공정입니다.']]);
        }
        $wo  = WorkOrder::whereKey($op->work_order_idx)->lockForUpdate()->first();
        $ops = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        return [$wo, $ops, $ops->firstWhere('work_order_op_idx', $opIdx)];
    }

    private function nextNo(string $table, string $col, string $prefix, Carbon $date): string {
        return \MesCore\Support\DocNumber::next($prefix . '-' . $date->format('ymd') . '-', $table, $col);
    }
}

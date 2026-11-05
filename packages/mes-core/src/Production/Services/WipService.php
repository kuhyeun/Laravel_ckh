<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;

/**
 * 재공( 공정 사이 반제품 ) 이력 — 자재관리 > 재공관리 ( 3010 · 11-05 사용자 정의 )
 *
 *   공정 실적 1건 → 이력 줄
 *     MAKE : 그 공정 +수량 ( 생산 )
 *     FEED : 바로 앞 수량 공정 −수량 ( 다음 공정 투입 — 앞 공정에서 만든 것을 가지고 만들었다 )
 *   예 ) 품목 A = 공정 1 → 공정 2 : 공정 1 실적 30 → 공정 1 +30 / 공정 2 실적 30 → 공정 1 −30 · 공정 2 +30
 *   마지막 공정( 제품 입고가 따른다 )도 +수량까지만 — 입고 · 출고는 재고 쪽 이력에서 본다
 *   수량을 쌓지 않는 공정( 원자재출고 · 검사 )은 재공이 생기지 않는다 → 앞 공정을 찾을 때 건너뛴다
 *   외주 공정 : 출고 때 앞 공정 − ( 외주 출고 ) · 입고 완료 때 외주 공정 + ( 합격분 실적 )
 *   실적 취소 : 지우지 않고 반대 줄( is_reversal = Y · 수량 부호 반대 )을 더한다
 *   ( 지시 × 공정 ) 의 합계가 그 공정에 남은 반제품 — 화면은 이력만 ( 11-05 )
 */
class WipService {

    public const KINDS = ['MAKE' => '생산', 'FEED' => '다음 공정 투입'];

    // 실적 입력 직후 ( OpResultService::addRun 트랜잭션 안 )
    public function recordRun(WorkOrder $wo, Collection $ops, WorkOrderOp $op, WorkOrderOpRun $run): void {
        if (!$op->isQtyOp()) {
            return;
        }
        $qty  = (float) $run->qty;
        $base = [
            'work_order_idx' => $wo->work_order_idx,
            'item_idx'       => $wo->item_idx,
            'run_idx'        => $run->run_idx,
            'is_reversal'    => 'N',
            'occurred_at'    => $run->worked_at,
            'operator_idx'   => $run->operator_idx,
        ];
        $rows = [];
        // 외주 공정 : 앞 공정 − 는 외주 출고 때 이미 남겼다 ( recordSubconIssue ) → 입고 합격분 + 만
        if (!$op->isSubconOp() && ($prev = $this->prevQtyOp($ops, $op))) {
            $rows[] = $base + ['work_order_op_idx' => $prev->work_order_op_idx, 'kind' => 'FEED', 'qty' => $this->str(-$qty), 'to_op_idx' => $op->work_order_op_idx];
        }
        $rows[] = $base + ['work_order_op_idx' => $op->work_order_op_idx, 'kind' => 'MAKE', 'qty' => $this->str($qty), 'to_op_idx' => null];
        $this->insert($rows);
    }

    // 실적 취소 직후 : 그 실적의 줄을 반대로 ( 언제 · 누가 취소했는지 )
    public function reverseRun(WorkOrderOpRun $run): void {
        $lines = DB::table('wip_txn')->where('run_idx', $run->run_idx)->where('is_reversal', 'N')->get();
        $this->insert($lines->map(fn ($l) => [
            'work_order_idx'    => $l->work_order_idx,
            'work_order_op_idx' => $l->work_order_op_idx,
            'item_idx'          => $l->item_idx,
            'run_idx'           => $l->run_idx,
            'kind'              => $l->kind,
            'is_reversal'       => 'Y',
            'qty'               => $this->str(-(float) $l->qty),
            'to_op_idx'         => $l->to_op_idx,
            'occurred_at'       => $run->canceled_at ?? now(),
            'operator_idx'      => $l->operator_idx,
        ])->all());
    }

    // 외주 출고 : 앞 수량 공정 − ( 외주처로 나감 — 외주 공정 + 는 입고 완료 때 실적으로 )
    public function recordSubconIssue(WorkOrder $wo, Collection $ops, WorkOrderOp $op, object $issue): void {
        $prev = $this->prevQtyOp($ops, $op);
        if (!$prev) {
            return;   // 첫 수량 공정이 외주 — 앞 재공이 없다
        }
        $this->insert([[
            'work_order_idx'    => $wo->work_order_idx,
            'work_order_op_idx' => $prev->work_order_op_idx,
            'item_idx'          => $wo->item_idx,
            'run_idx'           => null,
            'subcon_issue_idx'  => $issue->subcon_issue_idx,
            'kind'              => 'FEED',
            'is_reversal'       => 'N',
            'qty'               => $this->str(-(float) $issue->qty),
            'to_op_idx'         => $op->work_order_op_idx,
            'occurred_at'       => $issue->issued_at,
            'operator_idx'      => Auth::id(),
        ]]);
    }

    // 외주 출고 취소 : 반대 줄
    public function reverseSubconIssue(object $issue): void {
        $lines = DB::table('wip_txn')->where('subcon_issue_idx', $issue->subcon_issue_idx)->where('is_reversal', 'N')->get();
        $this->insert($lines->map(fn ($l) => [
            'work_order_idx'    => $l->work_order_idx,
            'work_order_op_idx' => $l->work_order_op_idx,
            'item_idx'          => $l->item_idx,
            'run_idx'           => null,
            'subcon_issue_idx'  => $l->subcon_issue_idx,
            'kind'              => $l->kind,
            'is_reversal'       => 'Y',
            'qty'               => $this->str(-(float) $l->qty),
            'to_op_idx'         => $l->to_op_idx,
            'occurred_at'       => now(),
            'operator_idx'      => $l->operator_idx,
        ])->all());
    }

    // 바로 앞의 수량 공정 ( 원자재출고 · 검사 공정은 건너뛴다 )
    public function prevQtyOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->isQtyOp())->sortBy('seq')->last();
    }

    private function insert(array $rows): void {
        if (!$rows) {
            return;
        }
        $by  = Auth::id() ?? 0;
        $now = now();
        DB::table('wip_txn')->insert(array_map(fn ($r) => $r + ['create_account_idx' => $by, 'create_datetime' => $now], $rows));
    }

    private function str(float $v): string {
        return number_format($v, 6, '.', '');
    }
}

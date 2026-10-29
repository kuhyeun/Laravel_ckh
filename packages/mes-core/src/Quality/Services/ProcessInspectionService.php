<?php

namespace MesCore\Quality\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Services\OpResultService;
use MesCore\Quality\Models\Inspection;

/**
 * 공정검사 ( 6002 · QUA-02 ) — docs/screens/70-품질.md §3
 *
 *   검사 공정 = 라우팅의 검사 공정( process_type INSPECTION ). 공정실적 · 공정검사 두 곳에서 같은 검사 창
 *   일반( NORMAL ) : 앞 공정이 모두 끝난 뒤 검사 수량( = 기준 = 앞 공정 수량 ) → 합격 + 불합격 = 검사 수량 → [검사 완료]
 *   초 · 중 · 종( IFL ) : 켜진 단계마다 판정 기록( 합격 / 불합격 · 불량 수량 ) — 초물 · 중물은 앞 공정 진행 중에도, 종물은 앞 공정이 끝난 뒤
 *                        모든 단계의 마지막 판정이 합격이어야 [검사 완료] · 합격 수량 = 검사 수량 − 단계 불량 합
 *   완료 = OpResultService::completeOp( _pass_qty ) — 공정 수량 = 합격 → 뒤 공정 기준 · 제품 입고는 합격분만 ( 결정 3 )
 *   취소 = 공정실적의 [완료 취소] ( 종합 기록 · 불량은 취소 표시 — OpResultService::reopenOp ) / 단계 기록은 공정 완료 전 하나씩 취소
 *   불량 = 발생 공정( work_order_op ) · 품목 = 만드는 제품 · LOT = 지시 LOT
 */
class ProcessInspectionService {

    public const REF_TYPE = 'work_order_op';
    public const STAGE_TYPES = ['FIRST' => 'IFL_FIRST', 'MIDDLE' => 'IFL_MIDDLE', 'LAST' => 'IFL_LAST'];
    public const STAGE_NAMES = ['FIRST' => '초물', 'MIDDLE' => '중물', 'LAST' => '종물'];

    private const EPS = 0.000001;

    public function __construct(private OpResultService $results, private DefectService $defects) {}

    /** @return array{0: WorkOrder, 1: Collection} 품목 줄 · 공정 ( 순서대로 ) */
    public function context(WorkOrderOp $op): array {
        $wo  = WorkOrder::with(['item', 'lot'])->find($op->work_order_idx);
        $ops = WorkOrderOp::with('process')->where('work_order_idx', $op->work_order_idx)->orderBy('seq')->get();
        return [$wo, $ops];
    }

    // 지금 검사 완료( 일반 · IFL 종합 )를 할 수 없는 이유
    public function completeBlock(WorkOrder $wo, Collection $ops, WorkOrderOp $op): ?string {
        if (!in_array($wo->status, ['WAIT', 'RUN'], true)) return '대기 · 진행 중인 품목 줄만 검사합니다.';
        if ($op->status === 'DONE') return null;
        $open = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->status !== 'DONE')->first();
        if ($open) return "앞 공정( {$this->results->opName($open)} )이 끝난 뒤에 검사 완료합니다.";
        if ($op->isIflOp()) {
            $latest = $this->stageLatest($op);
            foreach ($op->iflStages() as $st) {
                if (($latest[$st]?->result ?? null) !== 'PASS') return self::STAGE_NAMES[$st] . ' 검사가 합격이어야 완료합니다.';
            }
        }
        return null;
    }

    // 초 · 중 · 종 단계 기록을 할 수 없는 이유
    public function stageBlock(WorkOrder $wo, Collection $ops, WorkOrderOp $op, string $stage): ?string {
        if (!in_array($wo->status, ['WAIT', 'RUN'], true) || $op->status === 'DONE') return '완료된 공정입니다.';
        if (!in_array($stage, $op->iflStages(), true)) return '이 공정에서 켜지 않은 검사입니다.';
        $prev = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq)->last();
        if ($prev && $stage === 'LAST' && $prev->status !== 'DONE') return "종물은 앞 공정( {$this->results->opName($prev)} )이 끝난 뒤에 검사합니다.";
        if ($prev && $prev->status === 'WAIT') return "앞 공정( {$this->results->opName($prev)} )이 시작되지 않았습니다.";
        return null;
    }

    /**
     * 일반 검사 완료 / IFL 종합 완료
     * @param array{pass_qty:?string, fail_qty:?string, defect_type_code:?string, reason:?string, inspector_idx:int, inspected_at:string, remark:?string, warehouse_idx:?int} $data
     * @return string[] 경고
     */
    public function complete(WorkOrderOp $op, array $data): array {
        $at = $this->at($data['inspected_at']);
        return DB::transaction(function () use ($op, $data, $at) {
            $op = WorkOrderOp::with('process')->whereKey($op->work_order_op_idx)->lockForUpdate()->first();
            [$wo, $ops] = $this->context($op);
            $this->assertInspection($op);
            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['op' => ['이미 검사 완료한 공정입니다.']]);
            }
            if ($why = $this->completeBlock($wo, $ops, $op)) {
                throw ValidationException::withMessages(['op' => [$why]]);
            }
            $base = (float) $this->results->baseQty($wo, $ops, $op);

            if ($op->isIflOp()) {
                $stageFail = (float) Inspection::where('ref_type', self::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
                    ->whereIn('insp_type', self::STAGE_TYPES)->where('is_canceled', 'N')->sum('fail_qty');
                $pass = max(0, $base - $stageFail);
                $fail = $base - $pass;
            } else {
                $pass = (float) ($data['pass_qty'] ?? 0);
                $fail = (float) ($data['fail_qty'] ?? 0);
                if (abs(($pass + $fail) - $base) > self::EPS) {
                    throw ValidationException::withMessages(['pass_qty' => ["합격과 불합격을 더하면 검사 수량( {$this->num($base)} )이 되어야 합니다."]]);
                }
                $this->defects->validate($fail, $data['defect_type_code'] ?? null, $data['reason'] ?? null);
            }

            $inspection = Inspection::create([
                'insp_type' => 'PROCESS', 'ref_type' => self::REF_TYPE, 'ref_idx' => $op->work_order_op_idx, 'mode' => 'SIMPLE',
                'result'    => $fail <= self::EPS ? 'PASS' : ($pass <= self::EPS ? 'FAIL' : 'PARTIAL'),
                'pass_qty'  => (string) $pass, 'fail_qty' => (string) $fail,
                'inspected_at' => $at, 'inspector_idx' => $data['inspector_idx'], 'remark' => $data['remark'] ?? null,
            ]);
            if (!$op->isIflOp()) {   // IFL 은 단계 기록 때 불량을 남겼다
                $this->defects->record($inspection, $this->defectRow($wo, $op, $fail, $data, $at));
            }

            return $this->results->completeOp($op, ['_pass_qty' => $pass, 'warehouse_idx' => $data['warehouse_idx'] ?? null]);
        });
    }

    /**
     * 초 · 중 · 종 단계 판정 기록
     * @param array{result:string, fail_qty:?string, defect_type_code:?string, reason:?string, inspector_idx:int, inspected_at:string, remark:?string} $data
     */
    public function recordStage(WorkOrderOp $op, string $stage, array $data): Inspection {
        $at = $this->at($data['inspected_at']);
        return DB::transaction(function () use ($op, $stage, $data, $at) {
            $op = WorkOrderOp::with('process')->whereKey($op->work_order_op_idx)->lockForUpdate()->first();
            [$wo, $ops] = $this->context($op);
            $this->assertInspection($op);
            if (!$op->isIflOp()) {
                throw ValidationException::withMessages(['op' => ['초 · 중 · 종 검사 공정이 아닙니다.']]);
            }
            if ($why = $this->stageBlock($wo, $ops, $op, $stage)) {
                throw ValidationException::withMessages(['stage' => [$why]]);
            }
            $fail = $data['result'] === 'FAIL' ? (float) ($data['fail_qty'] ?? 0) : 0.0;
            if ($fail < 0) {
                throw ValidationException::withMessages(['fail_qty' => ['불량 수량은 0 이상이어야 합니다.']]);
            }
            if ($data['result'] === 'FAIL' && $fail > 0) {
                $this->defects->validate($fail, $data['defect_type_code'] ?? null, $data['reason'] ?? null);
            }

            $inspection = Inspection::create([
                'insp_type' => self::STAGE_TYPES[$stage], 'ref_type' => self::REF_TYPE, 'ref_idx' => $op->work_order_op_idx, 'mode' => 'SIMPLE',
                'result'    => $data['result'], 'pass_qty' => '0', 'fail_qty' => (string) $fail,
                'inspected_at' => $at, 'inspector_idx' => $data['inspector_idx'],
                'remark'    => trim(($data['result'] === 'FAIL' && filled($data['reason'] ?? null) ? $data['reason'] . ' ' : '') . ($data['remark'] ?? '')) ?: null,
            ]);
            $this->defects->record($inspection, $this->defectRow($wo, $op, $fail, $data, $at));
            return $inspection;
        });
    }

    // 단계 기록 취소 — 공정 완료 전만
    public function cancelStage(Inspection $inspection): void {
        DB::transaction(function () use ($inspection) {
            $inspection = Inspection::whereKey($inspection->inspection_idx)->lockForUpdate()->first();
            $op = WorkOrderOp::find($inspection->ref_idx);
            if ($inspection->ref_type !== self::REF_TYPE || !in_array($inspection->insp_type, self::STAGE_TYPES, true) || $inspection->is_canceled === 'Y' || !$op || $op->status === 'DONE') {
                throw ValidationException::withMessages(['inspection' => ['취소할 수 있는 단계 기록이 아닙니다. ( 공정 완료 전만 )']]);
            }
            $this->defects->cancel($inspection);
            $inspection->fill(['is_canceled' => 'Y'])->save();
        });
    }

    /** @return array<string, ?Inspection> 단계별 마지막 판정 ( 취소 제외 ) */
    public function stageLatest(WorkOrderOp $op): array {
        $rows = Inspection::where('ref_type', self::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
            ->whereIn('insp_type', self::STAGE_TYPES)->where('is_canceled', 'N')->orderBy('inspected_at')->orderBy('inspection_idx')->get();
        $out = [];
        foreach (self::STAGE_TYPES as $st => $type) $out[$st] = $rows->where('insp_type', $type)->last();
        return $out;
    }

    // 이 공정의 검사 기록 전부 ( 취소 포함 · 시간 순 ) — 창의 이력
    public function history(WorkOrderOp $op): Collection {
        return Inspection::with(['defects', 'inspector:account_idx,user_id,user_name'])
            ->where('ref_type', self::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
            ->orderBy('inspected_at')->orderBy('inspection_idx')->get();
    }

    // ---- 내부 ----

    private function defectRow(WorkOrder $wo, WorkOrderOp $op, float $fail, array $data, Carbon $at): array {
        return [
            'item_idx' => $wo->item_idx, 'lot_idx' => (int) ($wo->lot_idx ?? 0), 'work_order_op_idx' => $op->work_order_op_idx,
            'qty' => $fail, 'defect_type_code' => $data['defect_type_code'] ?? null, 'reason' => $data['reason'] ?? null, 'occurred_at' => $at,
        ];
    }

    private function assertInspection(WorkOrderOp $op): void {
        if (!$op->isInspectionOp()) {
            throw ValidationException::withMessages(['op' => ['검사 공정이 아닙니다.']]);
        }
    }

    private function at(string $v): Carbon {
        $at = Carbon::parse($v);
        if ($at->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['inspected_at' => ['아직 오지 않은 시각입니다.']]);
        }
        return $at;
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.') ?: '0';
    }
}

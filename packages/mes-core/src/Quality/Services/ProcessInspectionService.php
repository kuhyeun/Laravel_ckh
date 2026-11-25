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
 *   11-18 : 검사는 공정검사 화면에서만 한다 ( 공정실적에서는 하지 않는다 ) · 두 가지가 한 목록에 나온다
 *   검사 공정 = 라우팅의 검사 공정( process_type INSPECTION ) — 회차 검사 ( 11-23 · docs/공정검사-회차-설계.md )
 *              앞 공정 실적이 쌓이는 대로 나눠 검사 : 검사 가능 = 앞 공정 실적 − 검사한 수량 · 회차 = 합격 + 불합격( 실적별 LOT 지시는 LOT 마다 )
 *              회차 저장 = complete() → OpResultService::recordInspectionRound ( 공정 수량 = 합격 합계 · 마지막 공정이면 합격분 입고 · 자동 완료 )
 *              회차 취소 = cancelRound() · [검사 완료]( 남기고 끝냄 — 미달 사유 ) = finish() · [완료 취소] = OpResultService::cancelComplete( 회차는 그대로 )
 *   초 · 중 · 종 = 생산 공정( 생산 · 설비생산 )의 옵션 — 그 공정을 시작할 때 · 도중 · 끝난 뒤의 판정 기록( 합격 / 불합격 · 불량 수량 )
 *              생산을 막지 않는다( 실적 · 완료와 무관 ) · 불량 수량은 실적에서 빼지 않고 불량 이력으로만 · 작업지시가 대기 · 진행인 동안 기록 · 취소
 *   불량 = 발생 공정( work_order_op ) · 품목 = 만드는 제품 · LOT = 지시 LOT
 */
class ProcessInspectionService {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

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

    // 지금 검사 회차를 넣을 수 없는 이유 ( 11-23 — 앞 공정이 진행 중이어도 실적이 있으면 검사한다 )
    public function completeBlock(WorkOrder $wo, Collection $ops, WorkOrderOp $op, ?array $latest = null): ?string {
        if (!in_array($wo->status, ['WAIT', 'RUN'], true)) return '대기 · 진행 중인 품목 줄만 검사합니다.';
        if ($op->status === 'DONE') return null;
        if ($why = $this->results->workBlock($ops, $op)) return $why;
        if ($this->results->inspectAvailable($wo, $ops, $op) <= self::EPS) {
            $prev = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && ($o->isQtyOp() || $o->isInspectionOp()))->last();
            return $prev ? "검사할 수량이 없습니다. ( 앞 공정 {$this->results->opName($prev)} 실적 {$this->num((float) $prev->qty)} · 검사 {$this->num($this->results->inspectedQty($op))} )" : '검사할 수량이 없습니다.';
        }
        return null;
    }

    // 초 · 중 · 종 단계 기록을 할 수 없는 이유 — 생산 진행과는 묶지 않는다 ( 11-18 · 공정이 끝난 뒤에도 종물을 기록한다 )
    public function stageBlock(WorkOrder $wo, Collection $ops, WorkOrderOp $op, string $stage): ?string {
        if (!in_array($wo->status, ['WAIT', 'RUN'], true)) return '대기 · 진행 중인 작업지시만 기록합니다.';
        if (!in_array($stage, $op->iflStages(), true)) return '이 공정에서 켜지 않은 검사입니다.';
        return null;
    }

    /**
     * 검사 회차 저장 ( 11-23 — 전의 한 번에 검사 완료 )
     *   지시 LOT 지시 : pass_qty + fail_qty = 이번 검사 수량 ( ≤ 검사 가능 )
     *   실적별 LOT 지시 : lots = [ { lot_idx, qty, fail_qty } ] — 앞 공정 실적 LOT 마다( 남은 수량까지 ) · 합격 = 검사 − 불합격
     * @return string[] 경고
     */
    public function complete(WorkOrderOp $op, array $data): array {
        $at = $this->at($data['inspected_at']);
        return DB::transaction(function () use ($op, $data, $at) {
            $op = WorkOrderOp::with('process')->whereKey($op->work_order_op_idx)->lockForUpdate()->first();
            [$wo, $ops] = $this->context($op);
            $this->assertInspection($op);
            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['op' => ['이미 검사를 완료한 공정입니다. 더 검사하려면 [완료 취소] 를 먼저 하세요.']]);
            }
            if ($why = $this->completeBlock($wo, $ops, $op)) {
                throw ValidationException::withMessages(['op' => [$why]]);
            }
            $avail = $this->results->inspectAvailable($wo, $ops, $op);

            if ($wo->isRunLot()) {
                $cands = collect($this->results->inspectionLotCandidates($wo, $ops, $op))->keyBy('lot_idx');
                $lots  = [];
                foreach (array_values($data['lots'] ?? []) as $i => $l) {
                    $q = (float) ($l['qty'] ?? 0);
                    $f = (float) ($l['fail_qty'] ?? 0);
                    if ($q <= self::EPS && $f <= self::EPS) continue;
                    $c = $cands->get((int) ($l['lot_idx'] ?? 0));
                    if (!$c) {
                        throw ValidationException::withMessages(["lots.{$i}.lot_idx" => ['검사할 수 없는 LOT 입니다. 새로고침하세요.']]);
                    }
                    if ($q > $c['left'] + self::EPS) {
                        throw ValidationException::withMessages(["lots.{$i}.qty" => ["{$c['lot_no']} : 남은 수량( {$this->num($c['left'])} )보다 많이 검사할 수 없습니다."]]);
                    }
                    if ($f < 0 || $f > $q + self::EPS) {
                        throw ValidationException::withMessages(["lots.{$i}.fail_qty" => ["{$c['lot_no']} : 불합격은 0 ~ 검사 수량( {$this->num($q)} ) 이어야 합니다."]]);
                    }
                    $lots[] = ['lot_idx' => (int) $c['lot_idx'], 'qty' => $q, 'fail_qty' => $f];
                }
                if (!$lots) {
                    throw ValidationException::withMessages(['lots' => ['검사할 LOT 와 수량을 넣으세요.']]);
                }
                $qty  = array_sum(array_column($lots, 'qty'));
                $fail = array_sum(array_column($lots, 'fail_qty'));
                $pass = $qty - $fail;
            } else {
                $pass = (float) ($data['pass_qty'] ?? 0);
                $fail = (float) ($data['fail_qty'] ?? 0);
                $qty  = $pass + $fail;
                if ($pass < 0 || $qty <= self::EPS) {
                    throw ValidationException::withMessages(['pass_qty' => ['이번에 검사한 수량( 합격 + 불합격 )을 넣으세요.']]);
                }
                $lots = [['lot_idx' => $wo->lot_idx ? (int) $wo->lot_idx : null, 'qty' => $qty, 'fail_qty' => $fail]];
            }
            if ($qty > $avail + self::EPS) {
                throw ValidationException::withMessages(['pass_qty' => ["이번 검사 수량( {$this->num($qty)} )이 검사할 수 있는 수량( {$this->num($avail)} )보다 많습니다."]]);
            }
            $this->defects->validateRows($fail, $data);   // 불량 여러 줄 ( 11-12 )

            $inspection = Inspection::create([
                'insp_type' => 'PROCESS', 'ref_type' => self::REF_TYPE, 'ref_idx' => $op->work_order_op_idx, 'mode' => 'SIMPLE',
                'result'    => $fail <= self::EPS ? 'PASS' : ($pass <= self::EPS ? 'FAIL' : 'PARTIAL'),
                'pass_qty'  => (string) $pass, 'fail_qty' => (string) $fail,
                'inspected_at' => $at, 'inspector_idx' => $data['inspector_idx'], 'remark' => $data['remark'] ?? null,
                'inspection_spec_idx' => $data['inspection_spec_idx'] ?? null,   // 보고 검사한 기준서 ( 11-18 )
            ]);
            app(InspectionReportService::class)->record($inspection, $data['measure'] ?? null);   // 성적서 ( 11-23 — 설정이 켜졌을 때만 · 측정값 필수 )
            // 불량 LOT : 불합격이 한 LOT 에서만 나왔으면 그 LOT ( 실적별 LOT ) · 아니면 지시 LOT
            $failLots = array_values(array_filter($lots, fn ($l) => $l['fail_qty'] > self::EPS && $l['lot_idx']));
            $base     = $this->defectBase($wo, $op, $at);
            if (count($failLots) === 1) $base['lot_idx'] = (int) $failLots[0]['lot_idx'];
            $this->defects->recordRows($inspection, $base, $fail, $data);

            return $this->results->recordInspectionRound($op, $inspection, $lots, isset($data['warehouse_idx']) ? (int) $data['warehouse_idx'] : null, $at);
        });
    }

    // [검사 완료] — 남은 수량을 검사하지 않고 끝낸다( 미달 사유 ) · 남은 게 없으면 그냥 완료 ( 11-23 )
    public function finish(WorkOrderOp $op, array $data): array {
        $this->assertInspection($op);
        return $this->results->completeOp($op, ['short_reason' => $data['short_reason'] ?? null, 'warehouse_idx' => $data['warehouse_idx'] ?? null]);
    }

    // 회차 취소 ( 11-23 )
    public function cancelRound(Inspection $inspection): void {
        if ($inspection->ref_type !== self::REF_TYPE || $inspection->insp_type !== 'PROCESS') {
            throw ValidationException::withMessages(['inspection' => ['검사 공정의 회차가 아닙니다.']]);
        }
        $this->results->cancelInspectionRound($inspection);
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
            if (!$op->isIflOp()) {
                throw ValidationException::withMessages(['op' => ['초 · 중 · 종 검사를 켠 생산 공정이 아닙니다.']]);
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
                'inspection_spec_idx' => $data['inspection_spec_idx'] ?? null,   // 보고 검사한 기준서 ( 11-18 )
                'remark'    => trim(($data['result'] === 'FAIL' && filled($data['reason'] ?? null) ? $data['reason'] . ' ' : '') . ($data['remark'] ?? '')) ?: null,
            ]);
            app(InspectionReportService::class)->record($inspection, $data['measure'] ?? null);   // 성적서 ( 11-23 — 설정이 켜졌을 때만 · 측정값 필수 )
            $this->defects->record($inspection, $this->defectRow($wo, $op, $fail, $data, $at));
            return $inspection;
        });
    }

    // 단계 기록 취소 — 작업지시가 대기 · 진행인 동안 ( 11-18 : 공정 완료와 묶지 않는다 )
    public function cancelStage(Inspection $inspection): void {
        DB::transaction(function () use ($inspection) {
            $inspection = Inspection::whereKey($inspection->inspection_idx)->lockForUpdate()->first();
            $op = WorkOrderOp::find($inspection->ref_idx);
            $open = $op && in_array(WorkOrder::whereKey($op->work_order_idx)->value('status'), ['WAIT', 'RUN'], true);
            if ($inspection->ref_type !== self::REF_TYPE || !in_array($inspection->insp_type, self::STAGE_TYPES, true) || $inspection->is_canceled === 'Y' || !$open) {
                throw ValidationException::withMessages(['inspection' => ['취소할 수 있는 단계 기록이 아닙니다. ( 대기 · 진행 중인 작업지시만 )']]);
            }
            $this->defects->cancel($inspection);
            $inspection->fill(['is_canceled' => 'Y'])->save();
        });
    }

    /**
     * 검사일시 수정 ( 11-25 ) — 회차 · 초중종 기록 하나의 검사일시만 ( 판정 · 수량은 그대로 ) · 불량 발생일시를 같이
     *   마지막 공정 회차면 합격분 입고( 전표 · 원장 · 줄 완료 시각 = 검사일시 )도 같이 옮긴다 → 소급 경고
     *   공정 시작 시각( 첫 회차 때 넣은 started_at )은 공정실적 몫이라 그대로
     * @return string[] 경고
     */
    public function editDate(Inspection $inspection, mixed $value, InspectionDateService $dates): array {
        $at = $dates->read($value);
        return DB::transaction(function () use ($inspection, $at, $dates) {
            if ($inspection->ref_type !== self::REF_TYPE) {
                throw ValidationException::withMessages(['inspection' => ['공정검사 기록이 아닙니다.']]);
            }
            WorkOrderOp::whereKey($inspection->ref_idx)->lockForUpdate()->first();   // 회차 저장 · 취소와 같은 순서 ( 공정 → 검사 )
            $inspection = $dates->lock($inspection->inspection_idx, ['PROCESS', ...array_values(self::STAGE_TYPES)]);
            $lines = $inspection->insp_type === 'PROCESS'
                ? $inspection->lots()->whereNotNull('stock_txn_line_idx')->pluck('stock_txn_line_idx')->all() : [];
            $warn  = $lines ? $dates->checkLedger($at) : null;
            $moved = array_values(array_filter(array_map(fn ($l) => $dates->moveStockLine((int) $l, $at), $lines)));
            $dates->move($inspection, $at, $moved ? ['stock_lines' => $moved] : []);
            return array_values(array_filter([$warn]));
        });
    }

    /** @return array<string, ?Inspection> 단계별 마지막 판정 ( 취소 제외 ) */
    /** 여러 공정의 stageLatest() 를 한 번에 — @return array<int, array> 공정 idx → 단계별 마지막 기록 */
    public function stageLatestMany(array $opIdxs): array {
        if (!$opIdxs) return [];
        $rows = Inspection::where('ref_type', self::REF_TYPE)->whereIn('ref_idx', $opIdxs)
            ->whereIn('insp_type', self::STAGE_TYPES)->where('is_canceled', 'N')->orderBy('inspected_at')->orderBy('inspection_idx')->get()->groupBy('ref_idx');
        $out = [];
        foreach ($opIdxs as $id) {
            $mine = $rows[$id] ?? collect();
            foreach (self::STAGE_TYPES as $st => $type) $out[$id][$st] = $mine->where('insp_type', $type)->last();
        }
        return $out;
    }

    public function stageLatest(WorkOrderOp $op): array {
        $rows = Inspection::where('ref_type', self::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
            ->whereIn('insp_type', self::STAGE_TYPES)->where('is_canceled', 'N')->orderBy('inspected_at')->orderBy('inspection_idx')->get();
        $out = [];
        foreach (self::STAGE_TYPES as $st => $type) $out[$st] = $rows->where('insp_type', $type)->last();
        return $out;
    }

    // 이 공정의 검사 기록 전부 ( 취소 포함 · 시간 순 ) — 창의 이력
    public function history(WorkOrderOp $op): Collection {
        return Inspection::with(['defects', 'inspector:account_idx,user_id,user_name', 'lots.lot:lot_idx,lot_no,lot_title'])
            ->where('ref_type', self::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
            ->orderBy('inspected_at')->orderBy('inspection_idx')->get();
    }

    // ---- 내부 ----

    // 불량 줄의 공통 값 ( 유형 · 수량 · 상세는 줄마다 — DefectService::recordRows )
    private function defectBase(WorkOrder $wo, WorkOrderOp $op, Carbon $at): array {
        return ['item_idx' => $wo->item_idx, 'lot_idx' => (int) ($wo->lot_idx ?? 0), 'work_order_op_idx' => $op->work_order_op_idx, 'occurred_at' => $at];
    }

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
}

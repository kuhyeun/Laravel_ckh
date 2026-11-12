<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderMaterial;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Models\WorkOrderRunInput;

/**
 * 투입 LOT 연결 ( docs/실적LOT-설계.md §5 — 실적 LOT 3단계 · 11-11 )
 *   후보 : 이 공정에 투입되는 자재( BOM 투입 지점 · 없으면 첫 수량 공정 )의 출고된 자재 LOT + 바로 앞 수량 공정의 실적 LOT ( 검사 공정은 건너뛴다 — LOT 를 그대로 넘김 )
 *   사람이 고르고 수량을 적는다 — 추적용 기록 ( 재고 · 사용처리와 무관 ) · 넘쳐도 경고만
 *     앞 공정 LOT : 끌어간 수량 합 > 그 LOT 실적 수량 / 자재 LOT : 이 지시에서 연결한 합 > 그 LOT 출고 수량
 *   필수 : RUN 방식 지시에서 후보가 있으면 1개 이상 ( 외주 입고로 들어오는 실적은 5단계 )
 *   나중에 추가 · 해제( 취소 표시 ) · 실적 LOT 가 뒤 공정에 연결돼 있으면 그 실적은 취소 못 함
 */
class RunInputService {
    use \MesCore\Support\FormatsQty;

    private const EPS = 0.000001;

    // 자재 LOT 를 고를 공정 = 자재의 투입 공정 · 그 공정이 수량 공정이 아니면( 원자재출고 · 검사 ) 그 뒤 첫 수량 공정 · 투입 공정이 없으면 첫 수량 공정
    public function materialRunOp(Collection $ops, ?int $inputOpIdx): ?WorkOrderOp {
        $at = $inputOpIdx ? $ops->firstWhere('work_order_op_idx', $inputOpIdx) : null;
        if ($at && $at->isQtyOp()) {
            return $at;
        }
        return $ops->first(fn (WorkOrderOp $o) => $o->isQtyOp() && (!$at || $o->seq > $at->seq));
    }

    // 바로 앞 수량 공정 ( 검사 · 시스템 공정은 건너뛴다 )
    public function prevQtyOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->isQtyOp())->last();
    }

    /**
     * @return array{materials: array, runs: array, prev_op: ?string}
     *   materials : [ lot_idx, lot_no, item_code, item_name, issued_qty, linked_qty ]
     *   runs      : [ lot_idx, lot_no, run_idx, worked_at, qty, linked_qty ]
     */
    public function candidates(WorkOrder $wo, Collection $ops, WorkOrderOp $op): array {
        // 자재 : 이 공정에 투입되는 자재 품목 ( 주 · 대체 ) — 투입 공정이 수량 공정이 아니면( 원자재출고 등 ) 그 뒤 첫 수량 공정에서 고른다
        $items = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->get()
            ->filter(fn (WorkOrderMaterial $m) => (int) $this->materialRunOp($ops, $m->work_order_op_idx)?->work_order_op_idx === (int) $op->work_order_op_idx)
            ->pluck('child_item_idx')->unique()->values()->all();
        $materials = [];
        if ($items) {
            $linked = $this->linkedOf(WorkOrderRunInput::TYPE_MATERIAL, $wo);
            $materials = DB::table('wo_stock as s')->join('lot as l', 'l.lot_idx', '=', 's.lot_idx')->join('item as i', 'i.item_idx', '=', 's.item_idx')
                ->where('s.work_order_idx', $wo->work_order_idx)->whereIn('s.item_idx', $items)->where('s.lot_idx', '>', 0)->where('s.issued_qty', '>', 0)
                ->orderBy('i.item_code')->orderBy('l.lot_no')
                ->get(['s.lot_idx', 'l.lot_no', 'i.item_code', 'i.item_name', 'i.spec_unit', 's.issued_qty'])
                ->map(fn ($r) => [
                    'lot_idx' => (int) $r->lot_idx, 'lot_no' => $r->lot_no, 'item_code' => $r->item_code, 'item_name' => $r->item_name, 'spec_unit' => $r->spec_unit,
                    'issued_qty' => (float) $r->issued_qty, 'linked_qty' => (float) ($linked[$r->lot_idx] ?? 0),
                ])->values()->all();
        }

        // 앞 공정 실적 LOT
        $prev = $this->prevQtyOp($ops, $op);
        $runs = [];
        if ($prev) {
            $linked = $this->linkedOf(WorkOrderRunInput::TYPE_RUN, $wo);
            $runs = WorkOrderOpRun::with('lot:lot_idx,lot_no')->where('work_order_op_idx', $prev->work_order_op_idx)
                ->where('is_canceled', 'N')->whereNotNull('lot_idx')->orderBy('worked_at')->orderBy('run_idx')->get()
                ->map(fn (WorkOrderOpRun $r) => [
                    'lot_idx' => (int) $r->lot_idx, 'lot_no' => $r->lot?->lot_no, 'run_idx' => (int) $r->run_idx,
                    'worked_at' => $r->worked_at?->format('Y-m-d H:i'), 'qty' => (float) $r->qty, 'linked_qty' => (float) ($linked[$r->lot_idx] ?? 0),
                ])->values()->all();
        }

        return ['materials' => $materials, 'runs' => $runs, 'prev_op' => $prev ? sprintf('%03d %s', $prev->seq, $prev->process?->process_name) : null];
    }

    /**
     * 화면이 보낸 투입( [ input_type, lot_idx, qty ] … )을 검사해 정리 → [ 정리된 투입, 경고 ]
     *   같은 LOT 를 두 번 보내면 수량을 합친다 · 후보가 아닌 LOT 는 막는다
     */
    public function normalize(WorkOrder $wo, Collection $ops, WorkOrderOp $op, array $inputs, bool $required): array {
        $cand = $this->candidates($wo, $ops, $op);
        $pool = [
            WorkOrderRunInput::TYPE_MATERIAL => collect($cand['materials'])->keyBy('lot_idx'),
            WorkOrderRunInput::TYPE_RUN      => collect($cand['runs'])->keyBy('lot_idx'),
        ];

        $merged = [];
        foreach (array_values($inputs) as $i => $in) {
            $type = (string) ($in['input_type'] ?? '');
            $lot  = (int) ($in['lot_idx'] ?? 0);
            $qty  = (float) ($in['qty'] ?? 0);
            if (!isset($pool[$type]) || !$pool[$type]->has($lot)) {
                throw ValidationException::withMessages(["inputs.{$i}.lot_idx" => ['고를 수 없는 투입 LOT 입니다. 새로고침하세요.']]);
            }
            if ($qty <= self::EPS) {
                throw ValidationException::withMessages(["inputs.{$i}.qty" => ['투입 수량은 0보다 커야 합니다.']]);
            }
            $key = "{$type}:{$lot}";
            $merged[$key] = ['input_type' => $type, 'lot_idx' => $lot, 'qty' => ($merged[$key]['qty'] ?? 0) + $qty];
        }

        $hasCandidate = $cand['materials'] || $cand['runs'];
        if ($required && $hasCandidate && !$merged) {
            throw ValidationException::withMessages(['inputs' => ['투입 LOT 를 하나 이상 고르세요. ( 공정 LOT 추적 )']]);
        }

        // 넘침 경고 ( 막지 않음 )
        $warnings = [];
        foreach ($merged as $m) {
            $c = $pool[$m['input_type']]->get($m['lot_idx']);
            if ($m['input_type'] === WorkOrderRunInput::TYPE_RUN && $c['linked_qty'] + $m['qty'] > $c['qty'] + self::EPS) {
                $warnings[] = "{$c['lot_no']} : 뒤 공정에서 끌어간 수량( {$this->num($c['linked_qty'] + $m['qty'])} )이 그 실적 수량( {$this->num($c['qty'])} )보다 많습니다.";
            }
            if ($m['input_type'] === WorkOrderRunInput::TYPE_MATERIAL && $c['linked_qty'] + $m['qty'] > $c['issued_qty'] + self::EPS) {
                $warnings[] = "{$c['lot_no']} : 연결한 수량( {$this->num($c['linked_qty'] + $m['qty'])} )이 이 지시에 출고된 수량( {$this->num($c['issued_qty'])} )보다 많습니다.";
            }
        }
        return [array_values($merged), $warnings];
    }

    public function save(WorkOrderOpRun $run, array $normalized): void {
        foreach ($normalized as $m) {
            WorkOrderRunInput::create([
                'run_idx'            => $run->run_idx,
                'input_type'         => $m['input_type'],
                'lot_idx'            => $m['lot_idx'],
                'qty'                => $this->str($m['qty']),
                'create_account_idx' => Auth::id() ?? 0,
                'create_datetime'    => now(),
            ]);
        }
    }

    // 해제 ( 취소 표시 )
    public function cancel(WorkOrderRunInput $input): void {
        if ($input->is_canceled === 'Y') {
            throw ValidationException::withMessages(['run_input_idx' => ['이미 해제된 연결입니다.']]);
        }
        $input->fill(['is_canceled' => 'Y', 'canceled_by' => Auth::id(), 'canceled_at' => now()])->save();
    }

    // 실적을 취소할 때 : 그 실적 LOT 가 뒤 공정 실적에 연결돼 있으면 막고 · 이 실적의 투입 연결은 함께 해제
    public function beforeCancelRun(WorkOrderOpRun $run): void {
        if ($run->lot_idx) {
            $used = WorkOrderRunInput::with('run.op.process')->where('lot_idx', $run->lot_idx)->where('is_canceled', 'N')
                ->whereHas('run', fn ($q) => $q->where('is_canceled', 'N'))->first();
            if ($used) {
                $o = $used->run?->op;
                throw ValidationException::withMessages(['run_idx' => [
                    sprintf('이 실적 LOT 가 뒤 공정( %03d %s ) 실적에 투입 LOT 로 연결돼 있습니다. 그 연결을 먼저 해제하세요.', $o?->seq, $o?->process?->process_name),
                ]]);
            }
        }
        WorkOrderRunInput::where('run_idx', $run->run_idx)->where('is_canceled', 'N')
            ->update(['is_canceled' => 'Y', 'canceled_by' => Auth::id(), 'canceled_at' => now()]);
    }

    /** 실적별 투입 연결 ( 화면 ) — @return array<int, array> run_idx → [ … ] */
    public function byRuns(array $runIdxs): array {
        if (!$runIdxs) return [];
        return WorkOrderRunInput::with(['lot:lot_idx,lot_no', 'creator:account_idx,user_id,user_name'])
            ->whereIn('run_idx', $runIdxs)->where('is_canceled', 'N')->orderBy('run_input_idx')->get()
            ->groupBy('run_idx')
            ->map(fn ($g) => $g->map(fn (WorkOrderRunInput $x) => [
                'run_input_idx' => (int) $x->run_input_idx,
                'input_type'    => $x->input_type,
                'lot_no'        => $x->lot?->lot_no,
                'qty'           => (float) $x->qty,
                'creator'       => $x->creator?->user_name ?: $x->creator?->user_id,
            ])->values()->all())
            ->all();
    }

    // 이 지시 안에서 그 종류로 연결된 수량 ( LOT 별 · 해제 · 취소 실적 제외 )
    private function linkedOf(string $type, WorkOrder $wo): array {
        return DB::table('work_order_run_input as x')->join('work_order_op_run as r', 'r.run_idx', '=', 'x.run_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->where('op.work_order_idx', $wo->work_order_idx)->where('x.input_type', $type)->where('x.is_canceled', 'N')->where('r.is_canceled', 'N')
            ->groupBy('x.lot_idx')->selectRaw('x.lot_idx, SUM(x.qty) as n')->pluck('n', 'x.lot_idx')->map(fn ($n) => (float) $n)->all();
    }
}

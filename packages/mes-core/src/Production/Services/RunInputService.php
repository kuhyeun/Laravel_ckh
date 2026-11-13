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
 *   자재 LOT 는 "어떤 LOT 가 쓰였나" 만 남긴다 — 수량 없음 ( 11-12 · 자재 수량은 출고 · 사용 · 반납이 관리한다 · qty = 0 으로 저장 )
 *   앞 공정 실적 LOT 는 수량을 적는다 ( 뒤 공정이 끌어간 수량 — 재공 · 외주 출고가 쓴다 ) · 끌어간 합 > 그 LOT 실적 수량이면 경고만
 *   필수 : RUN 방식 지시에서 후보가 있으면 1개 이상 ( 외주 입고로 들어오는 실적은 5단계 )
 *   나중에 추가 · 해제( 취소 표시 ) · 실적 LOT 가 뒤 공정에 연결돼 있으면 그 실적은 취소 못 함
 *   자동 ( 정책 production.op_lot_input_mode = AUTO · 11-12 ) : auto() 가 먼저 들어온 LOT 부터 고른다 — 사람이 고르지 않는다
 *     앞 공정 LOT = 작업 일시순 · 실적 수량만큼 ( 남은 양이 모자라면 마지막 LOT 에 나머지 — 넘침 경고 )
 *     자재 LOT = 입고일순으로 줄 세우고 이 공정의 누적 실적으로 "지금 쓰고 있을 LOT" 를 고른다 ( 실적이 두 LOT 에 걸치면 둘 다 · 주 자재 다음에 대체 자재 )
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
            $linked = $this->linkCountOf($wo);
            $materials = DB::table('wo_stock as s')->join('lot as l', 'l.lot_idx', '=', 's.lot_idx')->join('item as i', 'i.item_idx', '=', 's.item_idx')
                ->where('s.work_order_idx', $wo->work_order_idx)->whereIn('s.item_idx', $items)->where('s.lot_idx', '>', 0)->where('s.issued_qty', '>', 0)
                ->orderBy('i.item_code')->orderBy('l.lot_no')
                ->get(['s.lot_idx', 's.item_idx', 'l.lot_no', 'l.in_at', 'i.item_code', 'i.item_name', 'i.spec_unit', 's.issued_qty', 's.returned_qty'])
                ->map(fn ($r) => [
                    'lot_idx' => (int) $r->lot_idx, 'item_idx' => (int) $r->item_idx, 'in_at' => (string) $r->in_at,   // 자동 연결( 입고일 선입선출 )용
                    'lot_no' => $r->lot_no, 'item_code' => $r->item_code, 'item_name' => $r->item_name, 'spec_unit' => $r->spec_unit,
                    'issued_qty' => (float) $r->issued_qty, 'net_qty' => max(0.0, (float) $r->issued_qty - (float) $r->returned_qty),   // net = 출고 − 반납
                    'linked_count' => (int) ($linked[$r->lot_idx] ?? 0),   // 이 지시의 실적 몇 건에 연결돼 있나 ( 자재는 수량을 남기지 않는다 )
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
     * 투입 LOT 자동 고르기 ( LOT 투입 방식 = 자동 · 11-12 ) → normalize() 에 넘길 [ input_type, lot_idx, qty ] …
     *   $qty = 이번 실적 수량 · $before = 이 공정에 이미 쌓인 실적 ( 이번 것 빼고 )
     */
    public function auto(WorkOrder $wo, Collection $ops, WorkOrderOp $op, float $qty, float $before = 0.0): array {
        $cand = $this->candidates($wo, $ops, $op);
        $out  = [];

        // 앞 공정 실적 LOT : 작업 일시순( candidates 가 그 순서 ) · 실적 수량만큼 — 남은 양( 실적 − 끌어감 ) 안에서 · 모자라면 마지막 LOT 에 나머지
        $need = $qty;
        foreach ($cand['runs'] as $l) {
            if ($need <= self::EPS) break;
            $left = (float) $l['qty'] - (float) $l['linked_qty'];
            if ($left <= self::EPS) continue;
            $n     = min($left, $need);
            $out[] = ['input_type' => WorkOrderRunInput::TYPE_RUN, 'lot_idx' => $l['lot_idx'], 'qty' => round($n, 6)];
            $need -= $n;
        }
        if ($need > self::EPS && $cand['runs']) {
            $out[] = ['input_type' => WorkOrderRunInput::TYPE_RUN, 'lot_idx' => end($cand['runs'])['lot_idx'], 'qty' => round($need, 6)];
        }

        // 자재 LOT : 수량 없이 "어느 LOT 를 쓰고 있을 차례인가"
        //   자재 줄마다 LOT 를 입고일순으로 줄 세운다( 주 자재 → 대체 자재 · 대체는 주 자재 기준으로 환산 ) → 이 공정의 누적 사용량 구간[ 전 × 개당, ( 전 + 이번 ) × 개당 ]에 걸치는 LOT
        //   출고가 모자라 구간이 LOT 밖으로 나가면 마지막 LOT ( 추적이 끊기지 않게 )
        $byItem = collect($cand['materials'])->filter(fn ($l) => $l['net_qty'] > self::EPS)
            ->sortBy(fn ($l) => [$l['in_at'] ?: '9999', $l['lot_idx']])->groupBy('item_idx')->map(fn ($g) => $g->values()->all());
        $per  = fn (WorkOrderMaterial $m) => \MesCore\Support\MaterialNeed::perUnit((float) $m->qty_per, $m->yield_qty);
        $rows = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get()
            ->filter(fn (WorkOrderMaterial $m) => (int) $this->materialRunOp($ops, $m->work_order_op_idx)?->work_order_op_idx === (int) $op->work_order_op_idx);
        $picked = [];
        foreach ($rows->whereNull('substitute_of_idx') as $m) {
            if ($per($m) <= 0) continue;
            $line = [];   // [ lot_idx, 주 자재 기준 수량 ] — 쓰는 순서
            foreach ($byItem->get((int) $m->child_item_idx, []) as $l) {
                $line[] = [$l['lot_idx'], $l['net_qty']];
            }
            foreach ($rows->where('substitute_of_idx', $m->work_order_material_idx) as $s) {
                if ($per($s) <= 0) continue;
                foreach ($byItem->get((int) $s->child_item_idx, []) as $l) {
                    $line[] = [$l['lot_idx'], $l['net_qty'] * $per($m) / $per($s)];
                }
            }
            if (!$line) continue;
            [$from, $to] = [$before * $per($m), ($before + $qty) * $per($m)];
            $pos = 0.0;
            $hit = [];
            foreach ($line as [$lot, $amount]) {
                [$start, $end] = [$pos, $pos + $amount];
                if ($start < $to - self::EPS && $end > $from + self::EPS) $hit[] = $lot;
                $pos = $end;
            }
            foreach ($hit ?: [end($line)[0]] as $lot) $picked[$lot] = true;
        }
        foreach (array_keys($picked) as $lot) {
            $out[] = ['input_type' => WorkOrderRunInput::TYPE_MATERIAL, 'lot_idx' => $lot, 'qty' => 0];
        }
        return $out;
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
            // 자재 LOT 는 수량을 남기지 않는다 ( 어떤 LOT 인지만 · 11-12 ) — 앞 공정 실적 LOT 만 수량 필수
            if ($type === WorkOrderRunInput::TYPE_MATERIAL) {
                $qty = 0.0;
            } elseif ($qty <= self::EPS) {
                throw ValidationException::withMessages(["inputs.{$i}.qty" => ['앞 공정 실적 LOT 의 투입 수량은 0보다 커야 합니다.']]);
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
        }
        return [array_values($merged), $warnings];
    }

    // 그 실적에 이미 연결된( 해제 안 한 ) LOT 를 또 연결하려 하면 막는다 — 나중에 더하기
    public function assertNotLinked(WorkOrderOpRun $run, array $normalized): void {
        $have = WorkOrderRunInput::with('lot:lot_idx,lot_no')->where('run_idx', $run->run_idx)->where('is_canceled', 'N')->get()
            ->keyBy(fn (WorkOrderRunInput $x) => "{$x->input_type}:{$x->lot_idx}");
        $dup = collect($normalized)->map(fn ($m) => $have->get("{$m['input_type']}:{$m['lot_idx']}"))->filter();
        if ($dup->isNotEmpty()) {
            throw ValidationException::withMessages(['inputs' => [
                '이미 이 실적에 연결된 LOT 입니다 — ' . $dup->map(fn ($x) => $x->lot?->lot_no ?? "#{$x->lot_idx}")->unique()->join(', ') . '. ( 바꾸려면 해제한 뒤 다시 연결 )',
            ]]);
        }
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
                'lot_idx'       => (int) $x->lot_idx,   // 나중에 더할 때 이미 연결된 LOT 를 후보에서 뺀다
                'lot_no'        => $x->lot?->lot_no,
                'qty'           => $x->input_type === WorkOrderRunInput::TYPE_MATERIAL ? null : (float) $x->qty,   // 자재 LOT 는 수량 없음
                'creator'       => $x->creator?->user_name ?: $x->creator?->user_id,
            ])->values()->all())
            ->all();
    }

    // 이 지시 안에서 자재 LOT 가 연결된 실적 수 ( LOT 별 · 해제 · 취소 실적 제외 )
    private function linkCountOf(WorkOrder $wo): array {
        return DB::table('work_order_run_input as x')->join('work_order_op_run as r', 'r.run_idx', '=', 'x.run_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->where('op.work_order_idx', $wo->work_order_idx)->where('x.input_type', WorkOrderRunInput::TYPE_MATERIAL)->where('x.is_canceled', 'N')->where('r.is_canceled', 'N')
            ->groupBy('x.lot_idx')->selectRaw('x.lot_idx, COUNT(*) as n')->pluck('n', 'x.lot_idx')->map(fn ($n) => (int) $n)->all();
    }

    // 이 지시 안에서 그 종류로 연결된 수량 ( LOT 별 · 해제 · 취소 실적 제외 )
    private function linkedOf(string $type, WorkOrder $wo): array {
        return DB::table('work_order_run_input as x')->join('work_order_op_run as r', 'r.run_idx', '=', 'x.run_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->where('op.work_order_idx', $wo->work_order_idx)->where('x.input_type', $type)->where('x.is_canceled', 'N')->where('r.is_canceled', 'N')
            ->groupBy('x.lot_idx')->selectRaw('x.lot_idx, SUM(x.qty) as n')->pluck('n', 'x.lot_idx')->map(fn ($n) => (float) $n)->all();
    }
}

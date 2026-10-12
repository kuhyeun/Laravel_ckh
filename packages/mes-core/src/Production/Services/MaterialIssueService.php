<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\ProcessEquipment;
use MesCore\Production\Calc\Formula;
use MesCore\Production\Calc\FormulaException;
use MesCore\Production\Models\CalcDef;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderMaterial;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\StockService;

/**
 * 자재출고 ( INV-03 · 생산 2단계 ) — docs/screens/50-생산.md §10 ~ §17 · 자재소요-계산-설계 §6 · §9
 *
 *   plan()   : 지시의 자재 줄마다 계산식으로 소요 → 규격별 묶음( 식에 SPEC 이 있으면 ) → 후보 LOT( 선입선출 ) 채움
 *   issue()  : 창고 → 지시. 원장 OUT( ISSUE_WO ) + wo_stock 보유 + 자재 줄 출고 합계. consume_timing = ISSUE 면 같은 자리에서 사용처리
 *   cancel() : 줄 단위 역분개. 그 LOT 의 보유분 안에서만 ( 이미 쓴 것은 못 되돌린다 )
 *
 *   계산 경고는 막지 않는다 ( W_* ). 한 LOT 의 잔고보다 많이 내는 것만 막는다 ( 그 조각이 없다 — §17-5 )
 */
class MaterialIssueService {

    public const TXN_TYPE  = 'ISSUE_WO';
    public const NO_PREFIX = 'IS';

    private const EPS = 0.000001;

    public function __construct(private StockService $stock, private PolicyService $policy) {}

    // ---- 소요 계산 ----

    public function plan(WorkOrder $wo): array {
        $wo->loadMissing(['item', 'ops.process', 'materials.childItem']);

        $ops       = $wo->ops->keyBy('work_order_op_idx');
        $firstOp   = $this->firstQtyOp($wo->ops);
        $equips    = ProcessEquipment::with('equipment')->whereIn('process_idx', $wo->ops->pluck('process_idx'))->get()->groupBy('process_idx');
        $defs      = CalcDef::where('is_use', 'Y')->get()->keyBy('calc_code');
        $itemIdxs  = $wo->materials->pluck('child_item_idx')->unique()->values();
        $lots      = $this->candidates($itemIdxs);
        $held      = $this->heldByItem($wo->work_order_idx);

        return $wo->materials->map(function (WorkOrderMaterial $m) use ($wo, $ops, $firstOp, $equips, $defs, $lots, $held) {
            $item     = $m->childItem;
            $warnings = [];

            // 식 : 자재 줄 → 자재 품목 → 정책 → BOM_BASIC ( 설계 §5 )
            $code = $m->calc_code ?: ($item->calc_code ?: ($this->policy->get('material.issue_calc') ?: CalcDef::DEFAULT_CODE));
            $def  = $this->def($defs, $code, $warnings);
            try {
                $tree = Formula::parse($def['expr']);
            } catch (FormulaException $e) {
                $warnings['W_CALC'] = "계산식 {$def['code']} 오류( {$e->getMessage()} ) — 개당 × 지시 수량으로 계산했습니다.";
                $def  = $this->def($defs, CalcDef::DEFAULT_CODE, $warnings);
                $tree = Formula::parse($def['expr']);
            }

            // 투입 공정 · SETUP ( 설계 §4.3 )
            $op = $m->work_order_op_idx ? $ops->get($m->work_order_op_idx) : $firstOp;
            [$setup, $setupSrc] = $this->setup($op, $equips, $warnings);

            $issued = (float) $m->issued_qty;
            $base   = (float) $m->qty_per * (float) $wo->target_qty;
            $cands  = $lots->get($m->child_item_idx) ?? collect();
            $vars   = [
                'ORDER_QTY' => [(float) $wo->target_qty, '작업지시'],
                'BOM_QTY'   => [(float) $m->qty_per, '자재 줄'],
                'SPEC2'     => [null, 'Phase 2'],
                'PACK'      => [$item->pack_qty !== null ? (float) $item->pack_qty : null, '품목 포장 수량'],
                'STOCK'     => [(float) $cands->sum('qty'), '현재고 합계'],
                'ISSUED'    => [$issued, '이미 출고'],
                'REMAIN'    => [max(0, $base - $issued), '개당 × 지시 − 출고'],
                'SETUP'     => [$setup, $setupSrc],
            ] + $this->customVars($m, $wo);

            $usesSpec = in_array('SPEC', Formula::variables($tree), true);
            $groups   = $this->groups($cands, $usesSpec, $item->min_usable_spec !== null ? (float) $item->min_usable_spec : null);

            foreach ($groups as &$g) {
                $v = $vars;
                if ($usesSpec) {
                    $v['SPEC'] = [$g['spec'], 'LOT 규격'];
                }
                $e = $this->evaluate($def, $tree, $v, $usesSpec);
                $g['excluded'] = $g['excluded'] || $e['excluded'];
                $g['reason']   = $g['reason'] ?? $e['reason'];
                $g['value']    = $g['excluded'] ? null : $e['value'];
                $g['warnings'] = $e['warnings'];
                $g['trace']    = $e['trace'];
                $g['stock']    = (float) collect($g['lots'])->sum('qty');
            }
            unset($g);

            // 기본 묶음 : 쓸 수 있는 가장 큰 규격 ( 자투리 최소 — 설계 §6.2 ). SPEC 을 안 쓰면 하나뿐
            $usable  = array_values(array_filter($groups, fn ($g) => !$g['excluded']));
            $default = $usable ? $usable[0] : null;
            if ($usesSpec && !$usable) {
                $warnings['W_NOLOT'] = $groups ? '물림 · 절단을 빼면 쓸 수 있는 LOT 이 없습니다. 더 긴 규격을 입고해야 합니다.' : '출고할 수 있는 재고가 없습니다.';
            }
            $result = $default['value'] ?? ($usesSpec ? null : $this->evaluate($def, $tree, $vars, false)['value']);
            if ($default) {
                $warnings += $default['warnings'];
            }
            if ($result !== null && $result > ($default['stock'] ?? 0) + self::EPS) {
                $warnings['W_OVER'] = '계산된 소요가 재고보다 많습니다. ( 부족 ' . $this->num($result - ($default['stock'] ?? 0)) . ' )';
            }

            // 채움 : 자동이면 기본 묶음의 LOT 을 선입선출로 위에서부터 ( 남은 소요 = 계산 − 이미 출고 — §17-4 )
            $mode = (string) ($this->policy->get('material.consume_mode', ['item' => $m->child_item_idx]) ?? 'AUTO');
            $need = max(0, ($result ?? 0) - $issued);
            if ($mode === 'AUTO' && $default) {
                $left = $need;
                foreach ($groups as &$g) {
                    if ($g['key'] !== $default['key']) {
                        continue;
                    }
                    foreach ($g['lots'] as &$l) {
                        $l['fill'] = $left > self::EPS ? (float) min($l['qty'], $left) : 0.0;
                        $left     -= $l['fill'];
                    }
                    unset($l);
                }
                unset($g);
            }

            $h = $held[$m->child_item_idx] ?? ['issued' => 0.0, 'consumed' => 0.0, 'qty' => 0.0];
            return [
                'work_order_material_idx' => $m->work_order_material_idx,
                'item_idx'      => $m->child_item_idx,
                'item_code'     => $item->item_code,
                'item_name'     => $item->item_name,
                'spec_unit'     => $item->spec_unit,
                'calc_code'     => $def['code'],
                'calc_name'     => $def['name'],
                'result_kind'   => $def['kind'],
                'op_label'      => $op ? sprintf('%03d %s', $op->seq, $op->process?->process_name) : '',
                'qty_per'       => (float) $m->qty_per,
                'required_qty'  => $base,
                'calc_qty'      => $result,
                'issued_qty'    => $issued,
                'consumed_qty'  => $h['consumed'],
                'held_qty'      => $h['qty'],
                'need_qty'      => $need,
                'consume_mode'  => $mode,
                'uses_spec'     => $usesSpec,
                'default_key'   => $default['key'] ?? null,
                'groups'        => array_map(fn ($g) => ['warnings' => array_values($g['warnings'])] + $g, $groups),
                'warnings'      => array_values($warnings),
            ];
        })->values()->all();
    }

    // ---- 출고 ----

    /**
     * @param array{occurred_at:string, remark:?string, lines: array<int, array{work_order_material_idx:int, stock_balance_idx:int, qty:string}>} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function issue(WorkOrder $wo, array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $txn = DB::transaction(function () use ($wo, $data, $occurredAt) {
            $wo = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $this->assertOpen($wo);

            $materials = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->get()->keyBy('work_order_material_idx');

            // 같은 자재 줄 × 잔고 행은 합친다
            $lines = [];
            foreach ($data['lines'] as $i => $l) {
                if ((float) $l['qty'] <= 0) {
                    continue;
                }
                $k = $l['work_order_material_idx'] . ':' . $l['stock_balance_idx'];
                $lines[$k] = ['i' => $lines[$k]['i'] ?? $i, 'm' => (int) $l['work_order_material_idx'], 'b' => (int) $l['stock_balance_idx'],
                              'qty' => ($lines[$k]['qty'] ?? 0) + (float) $l['qty']];
            }
            if (!$lines) {
                throw ValidationException::withMessages(['lines' => ['출고할 수량을 넣으세요.']]);
            }

            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::NO_PREFIX, $occurredAt),
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => $data['remark'] ?? null,
            ]);

            $n = 0;
            foreach ($lines as $l) {
                $m   = $materials->get($l['m']);
                $bal = StockBalance::with(['lot', 'warehouse'])->whereKey($l['b'])->lockForUpdate()->first();
                $err = "lines.{$l['i']}.qty";
                if (!$m) {
                    throw ValidationException::withMessages([$err => ['이 작업지시의 자재 줄이 아닙니다. 새로고침하세요.']]);
                }
                if (!$bal || (int) $bal->item_idx !== (int) $m->child_item_idx) {
                    throw ValidationException::withMessages([$err => ['재고를 찾을 수 없습니다. 새로고침하세요.']]);
                }
                if ($bal->warehouse?->is_use !== 'Y' || ($bal->lot_idx && !$bal->lot?->in_at)) {
                    throw ValidationException::withMessages([$err => ['출고할 수 없는 재고입니다. ( 미사용 창고 · 검사대기 LOT )']]);
                }
                if ($l['qty'] > (float) $bal->qty + self::EPS) {
                    $lot = $bal->lot?->lot_no ?? '';
                    throw ValidationException::withMessages([$err => ["{$lot} 잔고( {$this->num((float) $bal->qty)} )보다 많이 낼 수 없습니다."]]);
                }

                $qty      = $this->str($l['qty']);
                $timing   = (string) ($this->policy->get('material.consume_timing', ['item' => $m->child_item_idx]) ?? 'PROCESS_START');
                $consumed = $timing === 'ISSUE' ? $qty : '0';

                $line = StockTxnLine::create([
                    'stock_txn_idx'           => $txn->stock_txn_idx,
                    'line_no'                 => ++$n,
                    'item_idx'                => $bal->item_idx,
                    'lot_idx'                 => $bal->lot_idx,
                    'warehouse_idx'           => $bal->warehouse_idx,
                    'qty'                     => $qty,
                    'work_order_idx'          => $wo->work_order_idx,
                    'work_order_material_idx' => $m->work_order_material_idx,
                    'line_status'             => 'DONE',
                    'pass_qty'                => $qty,
                    'consumed_qty'            => $consumed,
                    'done_at'                 => $occurredAt,
                ]);

                $this->stock->post([
                    'stock_txn_idx'      => $txn->stock_txn_idx,
                    'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx'           => $bal->item_idx,
                    'lot_idx'            => $bal->lot_idx,
                    'warehouse_idx'      => $bal->warehouse_idx,
                    'txn_type'           => self::TXN_TYPE,
                ], '-' . $qty, $occurredAt);

                $this->bumpHeld($wo->work_order_idx, $bal->item_idx, $bal->lot_idx, $qty, $consumed);
                DB::table('work_order_material')->where('work_order_material_idx', $m->work_order_material_idx)
                    ->update(['issued_qty' => DB::raw('issued_qty + ' . $qty)]);
            }

            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    /**
     * 출고 취소 ( 줄 단위 ) — 보유분( 출고 − 사용 )이 남아 있어야 한다. 출고와 함께 사용처리한 몫은 같이 되돌린다
     * @param int[] $lineIdxs
     * @return int 취소한 줄 수
     */
    public function cancel(WorkOrder $wo, array $lineIdxs): int {
        return DB::transaction(function () use ($wo, $lineIdxs) {
            $wo = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $this->assertOpen($wo);

            $lines = StockTxnLine::with('lot')->whereIn('stock_txn_line_idx', $lineIdxs)
                ->where('work_order_idx', $wo->work_order_idx)->where('line_status', 'DONE')
                ->whereHas('txn', fn ($q) => $q->where('txn_type', self::TXN_TYPE))
                ->lockForUpdate()->get();
            if (!$lines->count()) {
                throw ValidationException::withMessages(['lines' => ['취소할 수 있는 출고 줄이 없습니다.']]);
            }

            foreach ($lines as $line) {
                $back = (float) $line->qty - (float) $line->consumed_qty;   // 보유에서 빠질 몫
                $held = (float) DB::table('wo_stock')->where(['work_order_idx' => $wo->work_order_idx, 'item_idx' => $line->item_idx, 'lot_idx' => $line->lot_idx])
                    ->lockForUpdate()->value('qty');
                if ($held + self::EPS < $back) {
                    $lot = $line->lot?->lot_no ?? '';
                    throw ValidationException::withMessages(['lines' => [
                        "{$lot} 은 이미 사용처리되어 되돌릴 수 없습니다. ( 출고 {$this->num((float) $line->qty)} · 보유 {$this->num($held)} )",
                    ]]);
                }

                StockLedger::where('stock_txn_line_idx', $line->stock_txn_line_idx)
                    ->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                    ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));

                $this->bumpHeld($wo->work_order_idx, $line->item_idx, $line->lot_idx, '-' . $this->str((float) $line->qty), '-' . $this->str((float) $line->consumed_qty));
                DB::table('work_order_material')->where('work_order_material_idx', $line->work_order_material_idx)
                    ->update(['issued_qty' => DB::raw('issued_qty - ' . $this->str((float) $line->qty))]);

                $line->fill(['line_status' => 'CANCELED'])->save();
            }

            // 전표의 줄이 모두 취소되면 전표도 취소
            foreach ($lines->pluck('stock_txn_idx')->unique() as $txnIdx) {
                if (!StockTxnLine::where('stock_txn_idx', $txnIdx)->where('line_status', '!=', 'CANCELED')->exists()) {
                    StockTxn::whereKey($txnIdx)->first()?->fill(['status' => 'CANCELED'])->save();
                }
            }

            return $lines->count();
        });
    }

    /** 품목별 보유 ( 출고 · 사용 · 보유 ) */
    public function heldByItem(int $woIdx): array {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)
            ->groupBy('item_idx')
            ->selectRaw('item_idx, SUM(issued_qty) AS issued, SUM(consumed_qty) AS consumed, SUM(qty) AS qty')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->item_idx => ['issued' => (float) $r->issued, 'consumed' => (float) $r->consumed, 'qty' => (float) $r->qty]])
            ->all();
    }

    public function hasIssue(int $woIdx): bool {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)->where('issued_qty', '>', 0)->exists();
    }

    // ---- 내부 ----

    private function assertOpen(?WorkOrder $wo): void {
        if (!$wo || !in_array($wo->status, ['WAIT', 'RUN'], true)) {
            $s = $wo ? WorkOrder::STATUSES[$wo->status] : '없는';
            throw ValidationException::withMessages(['work_order_idx' => ["{$s} 작업지시는 출고 · 출고 취소를 할 수 없습니다. ( 대기 · 진행만 )"]]);
        }
    }

    // wo_stock : 없으면 만들고 있으면 더한다 ( 보유 = 출고 − 사용 )
    private function bumpHeld(int $woIdx, int $itemIdx, int $lotIdx, string $issued, string $consumed): void {
        $held = $this->str((float) $issued - (float) $consumed);
        DB::statement(
            'INSERT INTO wo_stock ( work_order_idx, item_idx, lot_idx, issued_qty, consumed_qty, qty ) VALUES ( ?, ?, ?, ?, ?, ? )
             ON DUPLICATE KEY UPDATE issued_qty = issued_qty + VALUES( issued_qty ), consumed_qty = consumed_qty + VALUES( consumed_qty ), qty = qty + VALUES( qty )',
            [$woIdx, $itemIdx, $lotIdx, $issued, $consumed, $held]
        );
    }

    // 첫 수량 공정 : 시스템 · 검사 공정이 아닌 첫 공정 ( 없으면 첫 공정 )
    private function firstQtyOp(Collection $ops): ?WorkOrderOp {
        return $ops->first(fn (WorkOrderOp $o) => $o->process && !$o->process->isSystem() && $o->process->process_type !== Process::TYPE_INSPECTION)
            ?? $ops->first();
    }

    /** @return array{0: float, 1: string} SETUP 값과 출처 */
    private function setup(?WorkOrderOp $op, Collection $equips, array &$warnings): array {
        $proc = $op?->process;
        if (!$proc) {
            return [0.0, '투입 공정 없음'];
        }
        if ($proc->isSystem() || $proc->process_type === Process::TYPE_INSPECTION) {
            return [0.0, "{$proc->process_name} · 자르지 않는 공정"];
        }
        $cands = $equips->get($proc->process_idx) ?? collect();
        if ($op->equipment_idx && ($pe = $cands->firstWhere('equipment_idx', (int) $op->equipment_idx))) {
            return [(float) $pe->setup_loss, "{$proc->process_name} · 지정 설비 " . ($pe->equipment?->equipment_name ?? '')];
        }
        if ($cands->isEmpty()) {
            return [0.0, "{$proc->process_name} · 설비 후보 없음"];
        }
        $max = $cands->sortByDesc(fn ($pe) => (float) $pe->setup_loss)->first();
        if ((float) $max->setup_loss > 0) {
            $warnings['W_SETUP'] = '설비가 정해지지 않아 가장 불리한 물림값으로 계산했습니다. 설비를 정한 뒤 다시 계산할 수 있습니다.';
        }
        return [(float) $max->setup_loss, "{$proc->process_name} · 설비 미지정 → 후보 최댓값( " . ($max->equipment?->equipment_name ?? '') . ' )'];
    }

    /** 커스텀 변수 — config('mes.calc_vars'). 앞에서부터 값이 있는 첫 칸 */
    private function customVars(WorkOrderMaterial $m, WorkOrder $wo): array {
        $label = ['line' => '자재 줄', 'material' => '자재 품목', 'product' => '제품 품목'];
        $out   = [];
        foreach (config('mes.calc_vars', []) as $name => $sources) {
            if (isset(CalcDef::SYSTEM_VARS[$name])) {
                continue;   // 예약어는 덮어쓸 수 없다
            }
            $out[$name] = [null, '값 없음'];
            foreach ($sources as [$from, $col]) {
                $model = match ($from) { 'line' => $m, 'material' => $m->childItem, 'product' => $wo->item, default => null };
                $v = $model?->getAttribute($col);
                if ($v !== null && $v !== '') {
                    $out[$name] = [(float) $v, "{$label[$from]} {$col}"];
                    break;
                }
            }
        }
        return $out;
    }

    private function def(Collection $defs, string $code, array &$warnings): array {
        if ($d = $defs->get($code)) {
            return ['code' => $d->calc_code, 'name' => $d->name, 'expr' => $d->expr, 'kind' => $d->result_kind];
        }
        if (isset(CalcDef::BUILTINS[$code])) {
            $b = CalcDef::BUILTINS[$code];
            return ['code' => $code, 'name' => $b['name'], 'expr' => $b['expr'], 'kind' => $b['kind']];
        }
        $warnings['W_CALC'] = "계산식 {$code} 가 없어 개당 × 지시 수량으로 계산했습니다.";
        return $this->def($defs, CalcDef::DEFAULT_CODE, $warnings);
    }

    /** 후보 LOT : 잔고 > 0 · 사용 중 창고 · 재고에 잡힌 LOT. 선입선출( 잔재 우선 정책이면 잔재 먼저 ) */
    private function candidates(Collection $itemIdxs): Collection {
        if ($itemIdxs->isEmpty()) {
            return collect();
        }
        $remnantFirst = $this->policy->get('stock.remnant_consume') === 'REMNANT_FIRST';

        return DB::table('stock_balance as b')
            ->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 'b.lot_idx')
            ->whereIn('b.item_idx', $itemIdxs)->where('b.qty', '>', 0)->where('w.is_use', 'Y')
            ->where(fn ($q) => $q->where('b.lot_idx', 0)->orWhereNotNull('l.in_at'))
            ->when($remnantFirst, fn ($q) => $q->orderByRaw('l.parent_lot_idx IS NULL'))
            ->orderBy('l.in_at')->orderBy('l.lot_no')->orderBy('b.stock_balance_idx')
            ->get(['b.stock_balance_idx', 'b.item_idx', 'b.lot_idx', 'b.warehouse_idx', 'b.qty', 'w.warehouse_name', 'l.lot_no', 'l.spec_value', 'l.in_at', 'l.parent_lot_idx'])
            ->map(fn ($r) => [
                'stock_balance_idx' => $r->stock_balance_idx,
                'item_idx'          => $r->item_idx,
                'lot_idx'           => (int) $r->lot_idx,
                'lot_no'            => $r->lot_no,
                'spec_value'        => $r->spec_value !== null ? (float) $r->spec_value : null,
                'warehouse_idx'     => $r->warehouse_idx,
                'warehouse_name'    => $r->warehouse_name,
                'qty'               => (float) $r->qty,
                'in_at'             => $r->in_at ? substr($r->in_at, 0, 10) : null,
                'remnant'           => $r->parent_lot_idx !== null,
                'fill'              => 0.0,
            ])
            ->groupBy('item_idx');
    }

    /** 규격별 묶음 ( SPEC 을 쓰면 ) — 큰 규격 먼저. 규격 없는 LOT · 최소 사용 규격 미만은 제외로 표시 */
    private function groups(Collection $lots, bool $usesSpec, ?float $minUsable): array {
        if (!$usesSpec) {
            return [['key' => 'ALL', 'spec' => null, 'lots' => $lots->values()->all(), 'excluded' => false, 'reason' => null]];
        }
        return $lots->groupBy(fn ($l) => $l['spec_value'] === null ? 'NULL' : $this->str($l['spec_value']))
            ->map(function ($ls, $key) use ($minUsable) {
                $spec   = $ls->first()['spec_value'];
                $reason = $spec === null ? '규격 없음' : ($minUsable !== null && $spec < $minUsable ? '최소 사용 규격 미만' : null);
                return ['key' => (string) $key, 'spec' => $spec, 'lots' => $ls->values()->all(), 'excluded' => $reason !== null, 'reason' => $reason];
            })
            ->sortByDesc(fn ($g) => $g['spec'] ?? -INF)->values()->all();
    }

    /** 식 평가 + 후처리 + 계산 과정 */
    private function evaluate(array $def, array $tree, array $vars, bool $usesSpec): array {
        $warnings = [];
        $values   = array_map(fn ($v) => $v[0], $vars);
        $raw      = Formula::evaluate($tree, $values, $warnings);

        $value = match ($def['kind']) {
            'WEIGHT' => round($raw, 3),
            default  => ceil(round($raw, 9)),   // QTY · SPEC_COUNT : 올림 정수 ( 식에 CEIL 을 잊어도 — 설계 §6.3 )
        };
        if ($value < 0) {
            $warnings['W_NEG'] = '결과가 음수라 0 으로 보정했습니다.';
            $value = 0.0;
        }

        // 채취 0 개( 0 으로 나눔 ) → 이 규격은 못 쓴다
        $excluded = $usesSpec && isset($warnings['W_DIV0']);
        $used     = Formula::variables($tree);

        return [
            'value'    => $excluded ? null : (float) $value,
            'excluded' => $excluded,
            'reason'   => $excluded ? '물림 · 절단을 빼면 채취 0 개' : null,
            'warnings' => array_filter($warnings, fn ($k) => !($excluded && $k === 'W_DIV0'), ARRAY_FILTER_USE_KEY),
            'trace'    => [
                'expr'  => $def['expr'],
                'subst' => Formula::substitute($def['expr'], $values, fn ($v) => $this->str($v)),   // 식 안이라 천 단위 쉼표 없이
                'raw'   => round($raw, 6),
                'value' => $excluded ? null : (float) $value,
                'vars'  => array_values(array_map(fn ($n) => ['name' => $n, 'value' => $vars[$n][0] ?? null, 'source' => $vars[$n][1] ?? '정의 안 됨'], $used)),
            ],
        ];
    }

    private function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

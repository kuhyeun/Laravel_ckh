<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\ProcessEquipment;
use MesCore\Config\Models\Warehouse;
use MesCore\Production\Calc\Formula;
use MesCore\Production\Calc\FormulaException;
use MesCore\Production\Models\CalcDef;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderMaterial;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Stock\Models\Lot;
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
 *   consumeForOp() : 3단계 — 투입 공정이 시작될 때 그 공정 자재의 보유분을 사용처리. 시작된 뒤 출고하면 issue() 가 바로 사용처리
 *   returnMaterials() : 반납 — 출고한 그대로( 같은 LOT )를 창고로. 원장 IN_RETURN + · 보유에서 먼저, 모자라면 사용에서( 덜 썼다 )
 *                       품목 줄 상태와 상관없이 ( 완료 · 취소된 줄의 남은 자재도 ). 잔재 재입고( 새 LOT ) · 폐기는 따로( 재고-수불 §8 )
 *   returnCancel()    : 반납 취소 = 역분개. 그 LOT · 창고에 반납 수량만큼 재고가 남아 있을 때
 *   leftoverIn()      : 잔재 입고 ( 11-12 ) — 쓰고 남은 토막을 다른 품목( 규격이 다른 자재 )으로 창고에. 새 LOT( LEFTOVER · 원 LOT 의 잔재 ) · 원장 IN_LEFTOVER +
 *                       원 자재( 출고한 것 )의 수량은 건드리지 않는다 — 통째로 소모된 것 · 잔재 수량은 사람이 넣는다( 견주지 않음 )
 *   leftoverCancel()  : 잔재 입고 취소 = 역분개. 그 잔재 LOT · 창고에 재고가 남아 있을 때
 *
 *   계산 경고는 막지 않는다 ( W_* ). 한 LOT 의 잔고보다 많이 내는 것만 막는다 ( 그 조각이 없다 — §17-5 )
 *
 *   대체 자재 ( docs/대체자재-수주자재-설계.md §5.4 ) : 지시 자재의 대체 행( substitute_of_idx )도 같은 기계로 출고 · 보유 · 사용처리 · 반납
 *     plan() 은 주 자재 행 바로 뒤에 그 대체 행을 붙인다 — 대체 행은 후보 LOT 만 보이고 채우지 않는다( 자동 채움은 주 자재만 )
 *     채운 정도( 주 자재 기준 ) = 주 자재 출고 + Σ 대체 출고 × ( 주 소요 ÷ 대체 소요 ) → 주 자재의 남은 소요 · 자동 채움도 이것으로
 *     대체 행의 남은 소요 = 주 자재의 남은 소요를 대체 자재 기준으로 바꾼 값 ( 주 남음 × 대체 소요 ÷ 주 소요 )
 */
class MaterialIssueService {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

    public const TXN_TYPE  = 'ISSUE_WO';
    public const NO_PREFIX = 'IS';

    public const RETURN_TYPE   = 'IN_RETURN';
    public const RETURN_PREFIX = 'RT';
    public const LEFTOVER_TYPE   = 'IN_LEFTOVER';
    public const LEFTOVER_PREFIX = 'LO';
    public const RETURN_MODES  = ['RETURN'];   // 이번에 켠 처리 — 잔재 재입고( LEFTOVER ) · 폐기( SCRAP ) 는 자리만 ( 화면에서 꺼 둠 )

    private const EPS = 0.000001;

    public function __construct(private StockService $stock, private PolicyService $policy) {}

    // ---- 소요 계산 ----

    public function plan(WorkOrder $wo): array {
        $wo->loadMissing(['item', 'ops.process', 'materials.childItem', 'materials.substitutes.childItem']);

        $itemIdxs = $wo->materials->pluck('child_item_idx')
            ->merge($wo->materials->flatMap(fn ($m) => $m->substitutes->pluck('child_item_idx')))->unique()->values();
        $ctx = [
            'ops'     => $wo->ops->keyBy('work_order_op_idx'),
            'firstOp' => $this->firstQtyOp($wo->ops),
            'equips'  => ProcessEquipment::with('equipment')->whereIn('process_idx', $wo->ops->pluck('process_idx'))->get()->groupBy('process_idx'),
            'defs'    => CalcDef::where('is_use', 'Y')->get()->keyBy('calc_code'),
            'lots'    => $this->candidates($itemIdxs),
            'held'    => $this->heldByItem($wo->work_order_idx),
        ];
        $net = fn (WorkOrderMaterial $m) => (float) $m->issued_qty - (float) $m->returned_qty;   // 반납한 몫은 다시 필요하다

        $rows = [];
        foreach ($wo->materials as $m) {
            // 주 자재 : 채운 정도 = 자기 출고 + 대체 출고 환산 ( 주 소요 ÷ 대체 소요 )
            $per     = fn (WorkOrderMaterial $x) => \MesCore\Support\MaterialNeed::perUnit((float) $x->qty_per, $x->yield_qty);   // 생산 가능 수량이면 1 ÷ N ( 11-12 )
            $ratio   = fn (WorkOrderMaterial $s) => $per($s) > 0 ? $per($m) / $per($s) : 0.0;
            $equiv   = $m->substitutes->sum(fn ($s) => $net($s) * $ratio($s));
            $main    = $this->planRow($m, $wo, $ctx, $net($m) + $equiv, true, null);
            $main   += ['substitute_of_idx' => null, 'own_issued_qty' => $net($m), 'substitute_issued_equiv' => $equiv, 'substitute_count' => $m->substitutes->count()];
            $rows[]  = $main;

            foreach ($m->substitutes as $sub) {
                // 대체 : 남은 소요 = 주 자재 남음을 대체 자재 기준으로 · 채우지 않는다
                $toSub  = $per($m) > 0 ? $per($sub) / $per($m) : 0.0;
                $row    = $this->planRow($sub, $wo, $ctx, $net($sub), false, $main['need_qty'] * $toSub);
                $rows[] = $row + [
                    'substitute_of_idx' => $m->work_order_material_idx,
                    'main_item_code'    => $m->childItem?->item_code,
                    'main_item_name'    => $m->childItem?->item_name,
                    'ratio_to_main'     => $ratio($sub),   // 대체 1 = 주 자재 몇 ( 화면의 대체 포함 채움 )
                ];
            }
        }
        return $rows;
    }

    // 자재 행 하나의 계산 · 규격 묶음 · 후보 LOT ( $issued = 채운 정도 · $autoFill = 자동 채움 · $needOverride = 남은 소요를 바깥에서 )
    private function planRow(WorkOrderMaterial $m, WorkOrder $wo, array $ctx, float $issued, bool $autoFill, ?float $needOverride): array {
        ['ops' => $ops, 'firstOp' => $firstOp, 'equips' => $equips, 'defs' => $defs, 'lots' => $lots, 'held' => $held] = $ctx;
        $item     = $m->childItem;
        $warnings = [];

        // 식 : 자재 줄 → 자재 품목 → 정책 → BOM_BASIC ( 설계 §5 )
        $code = $m->calc_code ?: ($item->calc_code ?: ($this->policy->get('material.issue_calc') ?: CalcDef::DEFAULT_CODE));
        $def  = $this->def($defs, $code, $warnings);
        // 생산 가능 수량( 자재 1개로 제품 N개 · 11-12 ) 줄 : 계산식 대신 올림( 지시 수량 ÷ N )
        $yield = (float) ($m->yield_qty ?? 0);
        if ($yield > 0) {
            $def = ['code' => 'YIELD', 'name' => '생산 가능 수량', 'expr' => 'CEIL( ORDER_QTY / YIELD )', 'kind' => 'QTY'];
        }
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

        $base   = \MesCore\Support\MaterialNeed::of((float) $wo->target_qty, (float) $m->qty_per, $m->yield_qty);
        $cands  = $lots->get($m->child_item_idx) ?? collect();
        $vars   = [
            'ORDER_QTY' => [(float) $wo->target_qty, '작업지시'],
            'BOM_QTY'   => [(float) $m->qty_per, '자재 줄'],
            'YIELD'     => [$yield > 0 ? $yield : null, '자재 줄 — 자재 1개로 만드는 수'],
            'SPEC2'     => [null, 'Phase 2'],
            'PACK'      => [$item->pack_qty !== null ? (float) $item->pack_qty : null, '품목 포장 수량'],
            'STOCK'     => [(float) $cands->sum('qty'), '현재고 합계'],
            'ISSUED'    => [$issued, '이미 출고 ( 반납 뺌 )'],
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
        // 남은 소요( 계산 − 이미 출고 · 대체 환산 포함 )가 재고보다 많을 때만 — 11-12 : 전에는 전체 계산과 견줘 다 나간 자재에도 떴다
        $remain = $result === null ? 0.0 : max(0, $result - $issued);
        if ($needOverride === null && $remain > ($default['stock'] ?? 0) + self::EPS) {   // 대체 행은 주 자재 대신이라 전체 소요와 견주지 않는다
            $warnings['W_OVER'] = '남은 소요가 재고보다 많습니다. ( 부족 ' . $this->num($remain - ($default['stock'] ?? 0)) . ' )';
        }

        // 채움 : 자동이면 기본 묶음의 LOT 을 선입선출로 위에서부터 ( 남은 소요 = 계산 − 이미 출고 — §17-4 )
        $mode = (string) ($this->policy->get('material.consume_mode', ['item' => $m->child_item_idx]) ?? 'AUTO');
        $need = $needOverride ?? max(0, ($result ?? 0) - $issued);
        if ($autoFill && $mode === 'AUTO' && $default) {
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
            'item_spec'     => $item->spec,
            'item_material' => $item->material,
            'spec_unit'     => $item->spec_unit,
            'calc_code'     => $def['code'],
            'calc_name'     => $def['name'],
            'result_kind'   => $def['kind'],
            'op_label'      => $op ? sprintf('%03d %s', $op->seq, $op->process?->process_name) : '',
            'qty_per'       => (float) $m->qty_per,
            'yield_qty'     => $yield > 0 ? $yield : null,
            'required_qty'  => $base,
            'calc_qty'      => $result,
            'issued_qty'    => $issued,   // 주 자재 행 = 대체 출고 환산 포함 ( 채운 정도 )
            'consumed_qty'  => $h['consumed'],
            'held_qty'      => $h['qty'],
            'need_qty'      => $need,
            'consume_mode'  => $mode,
            'uses_spec'     => $usesSpec,
            'default_key'   => $default['key'] ?? null,
            'groups'        => array_map(fn ($g) => ['warnings' => array_values($g['warnings'])] + $g, $groups),
            'warnings'      => array_values($warnings),
        ];
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
            $ops       = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();

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
                // 출고 시점 사용 · 또는 투입 공정이 이미 시작됐으면 출고하자마자 사용 ( 3단계 — 공정을 시작한 뒤 늦게 낸 자재 )
                $consumed = ($timing === 'ISSUE' || $this->inputOpStarted($m, $ops)) ? $qty : '0';

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

            $this->syncRmOutStatus($wo, $ops, $occurredAt);
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

            $this->syncRmOutStatus($wo, WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get(), now());
            return $lines->count();
        });
    }

    /** 품목별 보유 ( 출고 · 사용 · 보유 ) */
    public function heldByItem(int $woIdx): array {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)
            ->groupBy('item_idx')
            ->selectRaw('item_idx, SUM(issued_qty) AS issued, SUM(consumed_qty) AS consumed, SUM(returned_qty) AS returned, SUM(qty) AS qty')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->item_idx => ['issued' => (float) $r->issued, 'consumed' => (float) $r->consumed, 'returned' => (float) $r->returned, 'qty' => (float) $r->qty]])
            ->all();
    }

    public function hasIssue(int $woIdx): bool {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)->where('issued_qty', '>', 0)->exists();
    }

    // ---- 반납 ----

    // 반납할 수 있는 것 : 지시가 출고한 LOT 마다 출고 · 사용 · 보유 · 반납 · 반납 가능( 출고 − 반납 ) · 기본 창고( 마지막으로 낸 창고 )
    public function returnPlan(WorkOrder $wo): array {
        $rows = DB::table('wo_stock as s')
            ->join('item as i', 'i.item_idx', '=', 's.item_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 's.lot_idx')
            ->where('s.work_order_idx', $wo->work_order_idx)->where('s.issued_qty', '>', 0)
            ->orderBy('i.item_code')->orderBy('l.lot_no')
            ->get(['s.item_idx', 's.lot_idx', 's.issued_qty', 's.consumed_qty', 's.returned_qty', 's.qty', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit', 'l.lot_no', 'l.spec_value']);

        // ( 품목, LOT ) 마다 마지막으로 낸 창고 — 한 번에 ( 전에는 LOT 마다 한 쿼리 )
        $lastWh = [];
        foreach (StockTxnLine::where('work_order_idx', $wo->work_order_idx)->where('line_status', 'DONE')
            ->whereHas('txn', fn ($q) => $q->where('txn_type', self::TXN_TYPE))
            ->orderBy('stock_txn_line_idx')->get(['item_idx', 'lot_idx', 'warehouse_idx']) as $l) {
            $lastWh["{$l->item_idx}:{$l->lot_idx}"] = $l->warehouse_idx;   // 뒤의 것이 덮는다 = 마지막
        }

        return $rows->map(fn ($r) => [
            'key'          => "{$r->item_idx}:{$r->lot_idx}",
            'item_idx'     => (int) $r->item_idx,
            'lot_idx'      => (int) $r->lot_idx,
            'item_code'    => $r->item_code,
            'item_name'    => $r->item_name,
            'item_spec'    => $r->item_spec,
            'item_material'=> $r->item_material,
            'spec_unit'    => $r->spec_unit,
            'lot_no'       => $r->lot_no,
            'spec_value'   => $r->spec_value !== null ? (float) $r->spec_value : null,
            'issued'       => (float) $r->issued_qty,
            'consumed'     => (float) $r->consumed_qty,
            'held'         => (float) $r->qty,
            'returned'     => (float) $r->returned_qty,
            'returnable'   => max(0, (float) $r->issued_qty - (float) $r->returned_qty),
            'warehouse_idx'=> $lastWh["{$r->item_idx}:{$r->lot_idx}"] ?? null,
        ])->values()->all();
    }

    /**
     * @param array{occurred_at:string, remark:?string, lines: array<int, array{item_idx:int, lot_idx:int, qty:string, warehouse_idx:int, mode:string}>} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function returnMaterials(WorkOrder $wo, array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $txn = DB::transaction(function () use ($wo, $data, $occurredAt) {
            $wo    = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $lines = array_values(array_filter($data['lines'], fn ($l) => (float) $l['qty'] > 0));
            if (!$lines) {
                throw ValidationException::withMessages(['lines' => ['반납할 수량을 넣으세요.']]);
            }

            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::RETURN_PREFIX, $occurredAt),
                'txn_type'    => self::RETURN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => $data['remark'] ?? "자재 반납 {$wo->wo_no}",
            ]);

            foreach ($lines as $n => $l) {
                $err = "lines.{$n}.qty";
                if (!in_array($l['mode'] ?? 'RETURN', self::RETURN_MODES, true)) {
                    throw ValidationException::withMessages([$err => ['잔재 재입고 · 폐기는 아직 준비 중입니다. ( 그대로 반납만 )']]);
                }
                $key   = ['work_order_idx' => $wo->work_order_idx, 'item_idx' => (int) $l['item_idx'], 'lot_idx' => (int) $l['lot_idx']];
                $stock = DB::table('wo_stock')->where($key)->lockForUpdate()->first();
                $wh    = Warehouse::whereKey($l['warehouse_idx'])->where('is_use', 'Y')->first();
                if (!$stock) {
                    throw ValidationException::withMessages([$err => ['이 작업지시가 출고한 자재가 아닙니다. 새로고침하세요.']]);
                }
                if (!$wh) {
                    throw ValidationException::withMessages(["lines.{$n}.warehouse_idx" => ['반납할 창고를 고르세요. ( 사용 중 창고 )']]);
                }
                $qty  = (float) $l['qty'];
                $left = (float) $stock->issued_qty - (float) $stock->returned_qty;
                if ($qty > $left + self::EPS) {
                    throw ValidationException::withMessages([$err => ["출고 {$this->num((float) $stock->issued_qty)} 중 반납할 수 있는 것은 {$this->num(max(0, $left))} 입니다."]]);
                }

                // 보유에서 먼저, 모자라면 사용에서 ( 투입 공정이 시작되면 보유를 모두 사용으로 돌리므로 — 실제로는 덜 썼다 )
                $fromHeld     = min($qty, (float) $stock->qty);
                $fromConsumed = $qty - $fromHeld;
                // 반납을 붙일 자재 줄 = 그 LOT 을 낸 출고 줄의 자재 줄 ( 같은 자재가 두 공정에 들어가면 줄마다 남은 소요가 따로라서 — 코드 검사 B-2 )
                //   출고 줄에 자재 줄이 없을 때만 그 품목의 첫 줄
                $issuedFor    = StockTxnLine::where('work_order_idx', $wo->work_order_idx)->where('item_idx', $l['item_idx'])->where('lot_idx', $l['lot_idx'])
                    ->where('line_status', 'DONE')->whereNotNull('work_order_material_idx')
                    ->whereHas('txn', fn ($q) => $q->where('txn_type', self::TXN_TYPE))
                    ->orderByDesc('stock_txn_line_idx')->value('work_order_material_idx');
                $material     = ($issuedFor ? WorkOrderMaterial::find($issuedFor) : null)
                    ?? WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->where('child_item_idx', $l['item_idx'])->orderBy('seq')->first();

                $line = StockTxnLine::create([
                    'stock_txn_idx'           => $txn->stock_txn_idx,
                    'line_no'                 => $n + 1,
                    'item_idx'                => (int) $l['item_idx'],
                    'lot_idx'                 => (int) $l['lot_idx'],
                    'warehouse_idx'           => $wh->warehouse_idx,
                    'qty'                     => $this->str($qty),
                    'work_order_idx'          => $wo->work_order_idx,
                    'work_order_material_idx' => $material?->work_order_material_idx,
                    'line_status'             => 'DONE',
                    'pass_qty'                => $this->str($qty),
                    'consumed_qty'            => $this->str($fromConsumed),   // 반납 줄 : 사용에서 되돌린 몫 ( 반납 취소 때 복원 )
                    'done_at'                 => $occurredAt,
                ]);
                $this->stock->post([
                    'stock_txn_idx'      => $txn->stock_txn_idx,
                    'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx'           => (int) $l['item_idx'],
                    'lot_idx'            => (int) $l['lot_idx'],
                    'warehouse_idx'      => $wh->warehouse_idx,
                    'txn_type'           => self::RETURN_TYPE,
                ], $this->str($qty), $occurredAt);

                DB::table('wo_stock')->where($key)->update([
                    'qty'          => DB::raw('qty - ' . $this->str($fromHeld)),
                    'consumed_qty' => DB::raw('consumed_qty - ' . $this->str($fromConsumed)),
                    'returned_qty' => DB::raw('returned_qty + ' . $this->str($qty)),
                ]);
                if ($material) {
                    DB::table('work_order_material')->where('work_order_material_idx', $material->work_order_material_idx)
                        ->update(['returned_qty' => DB::raw('returned_qty + ' . $this->str($qty))]);
                }
            }
            // 전부 반납했으면 원자재출고 공정을 대기로 ( 11-12 · 뒤 공정이 시작돼 있으면 그대로 )
            $this->syncRmOutStatus($wo, WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get(), $occurredAt);
            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    /**
     * 반납 취소 ( 줄 단위 · 역분개 ) — 그 LOT · 창고에 반납 수량만큼 재고가 있어야 ( 이미 다시 출고 · 이동했으면 막음 )
     * @param int[] $lineIdxs
     */
    public function returnCancel(WorkOrder $wo, array $lineIdxs): int {
        return DB::transaction(function () use ($wo, $lineIdxs) {
            // 출고 · 반납과 같은 순서로 지시 행부터 잠근다 · 취소된 지시는 막는다 ( 코드 검사 B-3 )
            $wo = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            if (!$wo || $wo->status === 'CANCELED') {
                throw ValidationException::withMessages(['lines' => ['취소된 작업지시는 반납을 되돌릴 수 없습니다.']]);
            }
            $lines = StockTxnLine::with('lot')->whereIn('stock_txn_line_idx', $lineIdxs)
                ->where('work_order_idx', $wo->work_order_idx)->where('line_status', 'DONE')
                ->whereHas('txn', fn ($q) => $q->where('txn_type', self::RETURN_TYPE))
                ->lockForUpdate()->get();
            if (!$lines->count()) {
                throw ValidationException::withMessages(['lines' => ['취소할 수 있는 반납 줄이 없습니다.']]);
            }
            foreach ($lines as $line) {
                $have = $this->stock->balanceQty($line->item_idx, (int) $line->lot_idx, $line->warehouse_idx);
                if ($have + self::EPS < (float) $line->qty) {
                    throw ValidationException::withMessages(['lines' => [
                        ( $line->lot?->lot_no ? "{$line->lot->lot_no} 의 " : '' ) . "창고 재고( {$this->num($have)} )가 반납 수량( {$this->num((float) $line->qty)} )보다 적어 취소할 수 없습니다. ( 다시 출고 · 이동됨 )",
                    ]]);
                }
                StockLedger::where('stock_txn_line_idx', $line->stock_txn_line_idx)->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                    ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));

                $toConsumed = (float) $line->consumed_qty;
                DB::table('wo_stock')->where(['work_order_idx' => $wo->work_order_idx, 'item_idx' => $line->item_idx, 'lot_idx' => $line->lot_idx])->update([
                    'qty'          => DB::raw('qty + ' . $this->str((float) $line->qty - $toConsumed)),
                    'consumed_qty' => DB::raw('consumed_qty + ' . $this->str($toConsumed)),
                    'returned_qty' => DB::raw('returned_qty - ' . $this->str((float) $line->qty)),
                ]);
                if ($line->work_order_material_idx) {
                    DB::table('work_order_material')->where('work_order_material_idx', $line->work_order_material_idx)
                        ->update(['returned_qty' => DB::raw('returned_qty - ' . $this->str((float) $line->qty))]);
                }
                $line->fill(['line_status' => 'CANCELED'])->save();
            }
            foreach ($lines->pluck('stock_txn_idx')->unique() as $txnIdx) {
                if (!StockTxnLine::where('stock_txn_idx', $txnIdx)->where('line_status', '!=', 'CANCELED')->exists()) {
                    StockTxn::whereKey($txnIdx)->first()?->fill(['status' => 'CANCELED'])->save();
                }
            }
            // 반납을 되돌려 다시 나간 자재가 생겼으면 원자재출고 공정을 진행으로 ( 11-12 )
            $this->syncRmOutStatus($wo, WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get(), now());
            return $lines->count();
        });
    }

    // ---- 잔재 입고 ( 다른 품목으로 · 11-12 ) ----

    /**
     * @param array{occurred_at:string, remark:?string, lines: array<int, array{source_item_idx:int, source_lot_idx:int, item_idx:int, qty:string, warehouse_idx:int}>} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function leftoverIn(WorkOrder $wo, array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $txn = DB::transaction(function () use ($wo, $data, $occurredAt) {
            $wo  = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::LEFTOVER_PREFIX, $occurredAt),
                'txn_type'    => self::LEFTOVER_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => $data['remark'] ?? "잔재 입고 {$wo->wo_no}",
            ]);
            $lotOn = $this->policy->isEnabled('feature.lot');

            foreach (array_values($data['lines']) as $n => $l) {
                // 원 자재 : 이 지시가 출고한 것이어야
                $src = DB::table('wo_stock as s')->join('item as i', 'i.item_idx', '=', 's.item_idx')->leftJoin('lot as t', 't.lot_idx', '=', 's.lot_idx')
                    ->where(['s.work_order_idx' => $wo->work_order_idx, 's.item_idx' => (int) $l['source_item_idx'], 's.lot_idx' => (int) $l['source_lot_idx']])
                    ->where('s.issued_qty', '>', 0)->first(['s.lot_idx', 'i.item_code', 't.lot_no', 't.partner_idx']);
                if (!$src) {
                    throw ValidationException::withMessages(["lines.{$n}.source_item_idx" => ['이 작업지시가 출고한 자재가 아닙니다. 새로고침하세요.']]);
                }
                $item = \MesCore\Config\Models\Item::whereKey($l['item_idx'])->where('is_use', 'Y')->first();
                if (!$item) {
                    throw ValidationException::withMessages(["lines.{$n}.item_idx" => ['잔재 품목을 고르세요. ( 사용 중 품목 )']]);
                }
                if ((int) $item->item_idx === (int) $l['source_item_idx']) {
                    throw ValidationException::withMessages(["lines.{$n}.item_idx" => ['원 자재와 같은 품목입니다. 안 쓴 자재를 그대로 돌려놓을 때는 [반납] 을 쓰세요.']]);
                }
                $wh = Warehouse::whereKey($l['warehouse_idx'])->where('is_use', 'Y')->first();
                if (!$wh) {
                    throw ValidationException::withMessages(["lines.{$n}.warehouse_idx" => ['넣을 창고를 고르세요. ( 사용 중 창고 )']]);
                }
                $qty = (float) $l['qty'];

                // 잔재 LOT : 원 LOT 번호 + -R순번 ( LOT 을 안 쓰면 LOT 없이 )
                $lot = null;
                if ($lotOn) {
                    $base = ($src->lot_no ?: $wo->wo_no) . '-R';
                    $seq  = (int) Lot::where('lot_no', 'like', $base . '%')->count();
                    do {
                        $lotNo = mb_substr($base . str_pad((string) ++$seq, 2, '0', STR_PAD_LEFT), 0, 50);
                    } while (Lot::where('lot_no', $lotNo)->exists());
                    $lot = Lot::create([
                        'item_idx'       => $item->item_idx,
                        'lot_no'         => $lotNo,
                        'partner_idx'    => $src->partner_idx,
                        'parent_lot_idx' => (int) $src->lot_idx ?: null,
                        'source'         => 'LEFTOVER',
                        'in_at'          => $occurredAt,
                    ]);
                }

                $line = StockTxnLine::create([
                    'stock_txn_idx'  => $txn->stock_txn_idx,
                    'line_no'        => $n + 1,
                    'item_idx'       => $item->item_idx,
                    'lot_idx'        => $lot?->lot_idx,
                    'warehouse_idx'  => $wh->warehouse_idx,
                    'qty'            => $this->str($qty),
                    'work_order_idx' => $wo->work_order_idx,
                    'line_status'    => 'DONE',
                    'pass_qty'       => $this->str($qty),
                    'done_at'        => $occurredAt,
                    'remark'         => mb_substr('원 자재 ' . $src->item_code . ($src->lot_no ? " · {$src->lot_no}" : ''), 0, 200),
                ]);
                $this->stock->post([
                    'stock_txn_idx'      => $txn->stock_txn_idx,
                    'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx'           => $item->item_idx,
                    'lot_idx'            => (int) $lot?->lot_idx,
                    'warehouse_idx'      => $wh->warehouse_idx,
                    'txn_type'           => self::LEFTOVER_TYPE,
                ], $this->str($qty), $occurredAt);
            }
            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    // 잔재 입고 취소 — 줄 단위 역분개 ( 그 잔재가 창고에 그대로 남아 있을 때만 · LOT 은 재고 0 으로 남는다 )
    public function leftoverCancel(WorkOrder $wo, array $lineIdxs): int {
        return DB::transaction(function () use ($wo, $lineIdxs) {
            WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $lines = StockTxnLine::with(['lot', 'item:item_idx,item_code'])->whereIn('stock_txn_line_idx', $lineIdxs)
                ->where('work_order_idx', $wo->work_order_idx)->where('line_status', 'DONE')
                ->whereHas('txn', fn ($q) => $q->where('txn_type', self::LEFTOVER_TYPE))
                ->lockForUpdate()->get();
            if (!$lines->count()) {
                throw ValidationException::withMessages(['lines' => ['취소할 수 있는 잔재 입고 줄이 없습니다.']]);
            }
            foreach ($lines as $line) {
                $have = $this->stock->balanceQty($line->item_idx, (int) $line->lot_idx, $line->warehouse_idx);
                if ($have + self::EPS < (float) $line->qty) {
                    throw ValidationException::withMessages(['lines' => [
                        ($line->lot?->lot_no ?: $line->item?->item_code) . " 의 창고 재고( {$this->num($have)} )가 잔재 수량( {$this->num((float) $line->qty)} )보다 적어 취소할 수 없습니다. ( 이미 쓰였거나 옮겨졌습니다 )",
                    ]]);
                }
                StockLedger::where('stock_txn_line_idx', $line->stock_txn_line_idx)->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                    ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
                $line->fill(['line_status' => 'CANCELED'])->save();
            }
            foreach ($lines->pluck('stock_txn_idx')->unique() as $txnIdx) {
                if (!StockTxnLine::where('stock_txn_idx', $txnIdx)->where('line_status', '!=', 'CANCELED')->exists()) {
                    StockTxn::whereKey($txnIdx)->first()?->fill(['status' => 'CANCELED'])->save();
                }
            }
            return $lines->count();
        });
    }

    /**
     * 사용처리 — 투입 공정이 시작될 때( 3단계 공정실적 ) 그 공정에 붙은 자재의 보유분을 전부 사용으로 돌린다
     *   투입 지점이 비어 있는 자재는 첫 수량 공정에 붙은 것으로 본다. 정책 consume_timing = ISSUE 인 자재는 이미 출고 때 사용했다
     *   원장은 남기지 않는다 ( wo_stock 만 — 재고-수불 §9.2-1 ). 호출하는 쪽의 트랜잭션 안에서
     * @return string[] 경고 ( 출고 안 된 자재 — W_MAT_NOT_ISSUED )
     */
    public function consumeForOp(WorkOrder $wo, WorkOrderOp $op): array {
        $ops      = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        $first    = $this->firstQtyOp($ops);
        $warnings = [];

        $materials = WorkOrderMaterial::with('childItem:item_idx,item_code,item_name,spec,material')->where('work_order_idx', $wo->work_order_idx)->get()
            ->filter(fn (WorkOrderMaterial $m) => (int) ($m->work_order_op_idx ?: $first?->work_order_op_idx) === (int) $op->work_order_op_idx);

        foreach ($materials->groupBy('child_item_idx') as $itemIdx => $rows) {
            $timing = (string) ($this->policy->get('material.consume_timing', ['item' => $itemIdx]) ?? 'PROCESS_START');
            if ($timing === 'ISSUE') {
                continue;
            }
            $held = DB::table('wo_stock')->where('work_order_idx', $wo->work_order_idx)->where('item_idx', $itemIdx)->where('qty', '>', 0)->lockForUpdate()->get();
            foreach ($held as $h) {
                DB::table('wo_stock')->where(['work_order_idx' => $wo->work_order_idx, 'item_idx' => $itemIdx, 'lot_idx' => $h->lot_idx])
                    ->update(['consumed_qty' => DB::raw('consumed_qty + qty'), 'qty' => 0]);
            }
        }

        // 경고 : 주 자재 기준으로만 ( 대체 자재 출고는 주 자재 환산에 포함 — 대체 자재를 안 냈다고 알리지 않는다 · 11-05 )
        //   주 + 대체 모두 안 나감 → 미출고 / 조금 나감 → 남은 소요 ( 주 자재 기준 · 대체 환산 포함 )
        $mainIdxs = $materials->filter(fn (WorkOrderMaterial $m) => $m->substitute_of_idx === null)->pluck('work_order_material_idx')->all();
        if ($mainIdxs) {
            foreach ($this->plan($wo->fresh()) as $r) {
                if (!empty($r['substitute_of_idx']) || !in_array($r['work_order_material_idx'], $mainIdxs) || $r['need_qty'] <= self::EPS) {
                    continue;
                }
                $name = trim("{$r['item_code']} {$r['item_name']}");
                $unit = $r['result_kind'] === 'SPEC_COUNT' ? '개' : ($r['spec_unit'] ? " {$r['spec_unit']}" : '');
                $subs = !empty($r['substitute_count']) ? ' ( 대체 자재 포함 )' : '';
                $warnings[] = $r['issued_qty'] <= self::EPS
                    ? "{$name} 은 아직 출고되지 않았습니다{$subs}. 자재출고에서 내면 그때 바로 사용처리합니다."
                    : "{$name} 출고가 {$this->num($r['need_qty'])}{$unit} 모자랍니다{$subs}. 더 내면 그때 바로 사용처리합니다.";
            }
        }
        return $warnings;
    }

    /**
     * consumeForOp() 되돌리기 — 투입 공정이 다시 대기로 돌아갈 때( 공정 완료 취소 ) 그 공정 자재의 사용분을 보유로 ( 11-09 코드 검사 B-1 )
     *   전에는 공정이 대기인데 자재가 '사용' 으로 남아 출고 취소가 "이미 사용처리" 로 막혔다
     *   같은 자재를 쓰는 다른 투입 공정이 시작돼 있으면 그대로 둔다 ( 그 공정이 쓴 몫과 나눌 수 없다 ) · 사용 시점이 '출고 때' 인 자재도 그대로
     */
    public function releaseForOp(WorkOrder $wo, WorkOrderOp $op): void {
        $ops       = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        $first     = $this->firstQtyOp($ops);
        $inputOpOf = fn (WorkOrderMaterial $m) => (int) ($m->work_order_op_idx ?: $first?->work_order_op_idx);
        $all       = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->get();

        foreach ($all->filter(fn ($m) => $inputOpOf($m) === (int) $op->work_order_op_idx)->groupBy('child_item_idx') as $itemIdx => $rows) {
            $timing = (string) ($this->policy->get('material.consume_timing', ['item' => $itemIdx]) ?? 'PROCESS_START');
            if ($timing === 'ISSUE') {
                continue;
            }
            $otherStarted = $all->filter(fn ($m) => (int) $m->child_item_idx === (int) $itemIdx && $inputOpOf($m) !== (int) $op->work_order_op_idx)
                ->contains(fn ($m) => ($ops->firstWhere('work_order_op_idx', $inputOpOf($m))?->status ?? 'WAIT') !== 'WAIT');
            if ($otherStarted) {
                continue;
            }
            $q = fn () => DB::table('wo_stock')->where('work_order_idx', $wo->work_order_idx)->where('item_idx', $itemIdx)->where('consumed_qty', '>', 0);
            $q()->lockForUpdate()->get();
            $q()->update(['qty' => DB::raw('qty + consumed_qty'), 'consumed_qty' => 0]);
        }
    }

    // ---- 내부 ----

    // 그 품목 줄에 나간 자재가 있는지 ( 출고 − 출고 취소 − 반납 > 0 · 주 · 대체 모두 ) — 원자재출고 완료 전 다음 공정 진행의 기준 ( 실적LOT-설계 §3 )
    //   11-12 : 반납한 몫을 뺀다 — 전부 반납했으면 나간 자재가 없는 것 ( 전에는 반납해도 '출고됨' 으로 남았다 )
    public function hasIssued(int $woIdx): bool {
        return (float) DB::table('work_order_material')->where('work_order_idx', $woIdx)->sum(DB::raw('issued_qty - returned_qty')) > self::EPS;
    }

    // 원자재출고 공정 상태를 다시 맞춘다 ( 실적 취소로 뒤 공정이 대기로 돌아갔을 때 — OpResultService · 11-12 )
    public function refreshRmOut(WorkOrder $wo, $ops): void {
        $this->syncRmOutStatus($wo, $ops, now());
    }

    // 원자재출고 공정 상태 ( 정책 production.op_before_rm_out_done 이 켜져 있을 때 — 완료 전에도 뒤 공정이 진행되므로 )
    //   첫 출고 → 진행( 시작 시각 = 출고 일시 ) · 출고를 모두 취소 → 대기 ( 뒤 공정이 이미 시작됐으면 그대로 — 거꾸로 꼬이지 않게 )
    //   품목 줄 · 지시서 상태도 맞춘다 ( 대기 ↔ 진행 )
    private function syncRmOutStatus(WorkOrder $wo, $ops, Carbon $at): void {
        if (!$this->policy->get('production.op_before_rm_out_done')) {
            return;
        }
        if (!in_array($wo->status, ['WAIT', 'RUN'], true)) {
            return;   // 완료 · 취소된 줄의 반납은 공정 · 줄 상태를 건드리지 않는다
        }
        $rm = $ops->first(fn (WorkOrderOp $o) => $o->isRmOut());
        if (!$rm || $rm->status === 'DONE') {
            return;
        }
        $issued = $this->hasIssued((int) $wo->work_order_idx);
        if ($issued && $rm->status === 'WAIT') {
            $rm->fill(['status' => 'RUN', 'started_at' => $at])->save();
        } elseif (!$issued && $rm->status === 'RUN') {
            $laterStarted = $ops->contains(fn (WorkOrderOp $o) => $o->seq > $rm->seq && ($o->status !== 'WAIT' || (float) $o->qty > self::EPS));
            if (!$laterStarted) {
                $rm->fill(['status' => 'WAIT', 'started_at' => null])->save();
            }
        } else {
            return;
        }
        $started    = $ops->contains(fn (WorkOrderOp $o) => $o->status !== 'WAIT');
        $wo->status = $started ? 'RUN' : 'WAIT';
        $wo->save();
        app(WorkOrderSheetService::class)->refreshStatus($wo->sheet);
    }

    private function assertOpen(?WorkOrder $wo): void {
        if (!$wo || !in_array($wo->status, ['WAIT', 'RUN'], true)) {
            $s = $wo ? WorkOrder::STATUSES[$wo->status] : '없는';
            throw ValidationException::withMessages(['work_order_idx' => ["{$s} 작업지시는 출고 · 출고 취소를 할 수 없습니다. ( 대기 · 진행만 )"]]);
        }
    }

    // wo_stock : 없으면 만들고 있으면 더한다 ( 보유 = 출고 − 사용 − 반납 )
    private function bumpHeld(int $woIdx, int $itemIdx, int $lotIdx, string $issued, string $consumed): void {
        $held = $this->str((float) $issued - (float) $consumed);
        DB::statement(
            'INSERT INTO wo_stock ( work_order_idx, item_idx, lot_idx, issued_qty, consumed_qty, qty ) VALUES ( ?, ?, ?, ?, ?, ? )
             ON DUPLICATE KEY UPDATE issued_qty = issued_qty + VALUES( issued_qty ), consumed_qty = consumed_qty + VALUES( consumed_qty ), qty = qty + VALUES( qty )',
            [$woIdx, $itemIdx, $lotIdx, $issued, $consumed, $held]
        );
    }

    // 자재의 투입 공정( 비면 첫 수량 공정 )이 이미 시작됐는지 — 대기가 아니면 시작
    private function inputOpStarted(WorkOrderMaterial $m, Collection $ops): bool {
        $op = $m->work_order_op_idx ? $ops->firstWhere('work_order_op_idx', $m->work_order_op_idx) : $this->firstQtyOp($ops);
        return $op && $op->status !== 'WAIT';
    }

    // 첫 수량 공정 : 시스템 · 검사 공정이 아닌 첫 공정 ( 없으면 첫 공정 )
    private function firstQtyOp(Collection $ops): ?WorkOrderOp {
        return $ops->first(fn (WorkOrderOp $o) => $o->isQtyOp()) ?? $ops->first();
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
}

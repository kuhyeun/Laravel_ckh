<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Services\BomService;
use MesCore\Config\Services\RoutingService;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\ProductionPlan;
use MesCore\Production\Models\ProductionPlanSource;

/**
 * 생산계획 저장 · 날짜 옮기기 · 삭제 / 취소 ( docs/생산계획-설계.md )
 *   계획 = 품목 하나 · 기간 · 수주 줄 여럿( 출처 — 이 계획 몫 수량 ) · 공정 · 자재는 수주 줄 것 ( 담긴 줄끼리 같아야 함 )
 *   같은 수주 줄의 계획 합이 수주 수량을 넘으면 경고 · 작업지시가 생긴 계획은 담긴 수주 줄을 바꿀 수 없고 지시 수량 아래로 줄일 수 없다
 */
class ProductionPlanService {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

    public function __construct(private RoutingService $routings, private BomService $boms) {}

    /**
     * @param array $data { sources: [{ sales_order_line_idx, qty }], start_date, end_date, remark? }
     *   담긴 수주 줄 : 품목이 같고 · 공정 순서 · 자재 구성이 같아야 한다( 그대로 작업지시 줄 하나가 된다 — 작업지시와 같은 규칙 )
     *   작업지시가 생긴 계획은 담긴 수주 줄을 바꿀 수 없고( 수량만 ) 지시 수량 아래로 줄일 수 없다
     * @return array{0: ProductionPlan, 1: string[]}
     */
    public function save(array $data, ?ProductionPlan $plan): array {
        $plan   ??= new ProductionPlan();
        $errors   = [];
        $warnings = [];

        if ($plan->exists && $plan->status === 'CANCELED') {
            throw ValidationException::withMessages(['status' => ['취소된 계획은 고칠 수 없습니다.']]);
        }

        $sources  = array_values($data['sources'] ?? []);
        $lineIdxs = array_map(fn ($s) => (int) $s['sales_order_line_idx'], $sources);
        $lines    = SalesOrderLine::with(['order', 'item'])->whereIn('sales_order_line_idx', $lineIdxs)->get()->keyBy('sales_order_line_idx');
        $oldIdxs  = $plan->exists ? $plan->sources()->pluck('sales_order_line_idx')->map(fn ($v) => (int) $v)->all() : [];
        $directed = $plan->exists ? $plan->directedQty() : 0.0;

        if (!$sources) {
            $errors['sources'] = ['수주 줄을 하나 이상 담으세요.'];
        }
        if (count($lineIdxs) !== count(array_unique($lineIdxs))) {
            $errors['sources'] = ['같은 수주 줄이 두 번 들어 있습니다.'];
        }
        if ($directed > 0 && (array_diff($lineIdxs, $oldIdxs) || array_diff($oldIdxs, $lineIdxs))) {
            $errors['sources'] = ['작업지시가 생긴 계획은 담긴 수주 줄을 바꿀 수 없습니다. ( 수량만 )'];
        }

        $itemIdx = null;
        foreach ($sources as $i => $s) {
            $line = $lines->get((int) $s['sales_order_line_idx']);
            if (!$line || !$line->order) {
                $errors["sources.{$i}.qty"] = ['수주 줄을 찾을 수 없습니다.'];
                continue;
            }
            $label = "{$line->order->order_no} #{$line->line_no}";
            if (!in_array((int) $line->sales_order_line_idx, $oldIdxs, true) && ($line->order->status !== 'CONFIRMED' || $line->line_status !== 'OPEN')) {
                $errors["sources.{$i}.qty"] = ["{$label} : 확정 수주의 진행 중 줄만 계획할 수 있습니다."];
                continue;
            }
            $itemIdx ??= (int) $line->item_idx;
            if ((int) $line->item_idx !== $itemIdx) {
                $errors["sources.{$i}.qty"] = ["{$label} : 품목이 첫 수주 줄과 다릅니다. ( 계획 하나 = 품목 하나 )"];
                continue;
            }
            // 같은 수주 줄의 다른 계획 몫 + 이 몫 > 수주 수량 → 경고
            $others = (float) ProductionPlanSource::where('sales_order_line_idx', $line->sales_order_line_idx)
                ->whereHas('plan', fn ($q) => $q->where('status', '!=', 'CANCELED')
                    ->when($plan->exists, fn ($w) => $w->where('production_plan_idx', '!=', $plan->production_plan_idx)))
                ->sum('qty');
            if ($others + (float) $s['qty'] > (float) $line->qty + 1e-9) {
                $warnings[] = "{$label} : 계획 합( " . $this->num($others + (float) $s['qty']) . ' )이 수주 수량( ' . $this->num((float) $line->qty) . ' )보다 많습니다.';   // W_PLAN_OVER
            }
            if ($line->due_date && $data['end_date'] > $line->due_date->toDateString()) {
                $warnings[] = "{$label} : 완료 예정일이 수주 납기( {$line->due_date->toDateString()} )보다 늦습니다.";   // W_PLAN_AFTER_DUE
            }
        }

        // 공정 순서 · 자재 구성이 다른 수주 줄은 한 계획에 담지 않는다 ( 첫 줄 기준 — 작업지시와 같은 규칙 )
        if (count($sources) > 1 && !$errors) {
            $pk = $this->routings->orderLineProcessKeys($lineIdxs);
            $mk = $this->boms->orderLineMaterialKeys($lineIdxs);
            foreach ($sources as $i => $s) {
                $id = (int) $s['sales_order_line_idx'];
                if ($i === 0) {
                    continue;
                }
                $line = $lines->get($id);
                if (($pk[$id] ?? '') !== ($pk[$lineIdxs[0]] ?? '')) {
                    $errors["sources.{$i}.qty"] = ["{$line->order->order_no} #{$line->line_no} : 첫 수주 줄과 공정이 달라 같이 담을 수 없습니다."];
                } elseif (($mk[$id] ?? '') !== ($mk[$lineIdxs[0]] ?? '')) {
                    $errors["sources.{$i}.qty"] = ["{$line->order->order_no} #{$line->line_no} : 첫 수주 줄과 자재가 달라 같이 담을 수 없습니다."];
                }
            }
        }

        $total = array_sum(array_map(fn ($s) => (float) $s['qty'], $sources));
        if ($total + 1e-9 < $directed) {
            $errors['sources'] = ['이미 지시된 수량( ' . $this->num($directed) . ' )보다 줄일 수 없습니다.'];
        }
        if ($data['end_date'] < $data['start_date']) {
            $errors['end_date'] = ['완료 예정일이 시작일보다 앞입니다.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($plan, $data, $sources, $itemIdx, $total) {
            $plan->fill([
                'item_idx'   => $itemIdx,
                'qty'        => (string) $total,
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
                'remark'     => $data['remark'] ?? null,
            ]);
            if (!$plan->exists) {
                $plan->plan_no = $this->nextPlanNo(now()->toDateString());
            }
            $plan->save();

            $keep = [];
            foreach ($sources as $s) {
                $row = ProductionPlanSource::updateOrCreate(
                    ['production_plan_idx' => $plan->production_plan_idx, 'sales_order_line_idx' => $s['sales_order_line_idx']],
                    ['qty' => (string) $s['qty']]
                );
                $keep[] = $row->production_plan_source_idx;
            }
            ProductionPlanSource::where('production_plan_idx', $plan->production_plan_idx)->whereNotIn('production_plan_source_idx', $keep ?: [0])->delete();
        });

        return [$plan->fresh(), $warnings];
    }

    // 계획 출처 수주 줄별 지시된 수량 ( 이 계획에서 만든 작업지시의 그 줄 몫 ) [ 수주 줄 IDX => 수량 ]
    public function directedBySource(array $planIdxs): array {
        if (!$planIdxs) {
            return [];
        }
        $out = [];
        DB::table('work_order_source as s')
            ->join('work_order as w', 'w.work_order_idx', '=', 's.work_order_idx')
            ->whereIn('w.production_plan_idx', $planIdxs)->where('w.status', '!=', 'CANCELED')
            ->groupBy('w.production_plan_idx', 's.sales_order_line_idx')->selectRaw('w.production_plan_idx, s.sales_order_line_idx, SUM(s.qty) qty')
            ->get()->each(function ($r) use (&$out) { $out[$r->production_plan_idx][$r->sales_order_line_idx] = (float) $r->qty; });
        return $out;
    }

    // 달력에서 끌어 옮기기 — 기간( 일수 )은 화면이 지킨다 · 취소된 계획은 못 옮김
    public function move(ProductionPlan $plan, string $start, string $end): ProductionPlan {
        if ($plan->status === 'CANCELED') {
            throw ValidationException::withMessages(['status' => ['취소된 계획은 옮길 수 없습니다.']]);
        }
        if ($end < $start) {
            throw ValidationException::withMessages(['end_date' => ['완료 예정일이 시작일보다 앞입니다.']]);
        }
        $plan->update(['start_date' => $start, 'end_date' => $end]);
        return $plan;
    }

    /**
     * 작업지시가 없으면 지우고, 있으면( 모두 취소된 지시만 ) 계획 취소 · 살아 있는 지시가 있으면 막는다
     * @return string 'deleted' | 'canceled'
     */
    public function delete(ProductionPlan $plan): string {
        if ($plan->workOrders()->where('status', '!=', 'CANCELED')->exists()) {
            throw ValidationException::withMessages(['plan' => ["{$plan->plan_no} 는 작업지시가 있어 지울 수 없습니다. 작업지시를 먼저 취소하세요."]]);
        }
        if ($plan->workOrders()->exists()) {
            $plan->update(['status' => 'CANCELED']);
            return 'canceled';
        }
        $plan->delete();
        return 'deleted';
    }

    // PP-YYMMDD-NNN ( 등록일 기준 날짜별 순번 )
    public function nextPlanNo(string $date): string {
        return \MesCore\Support\DocNumber::next(ProductionPlan::NO_PREFIX . date('ymd', strtotime($date)) . '-', 'production_plan', 'plan_no');
    }
}

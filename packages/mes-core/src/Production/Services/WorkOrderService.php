<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\ProcessEquipment;
use MesCore\Config\Models\RoutingChangeLog;
use MesCore\Config\Models\RoutingLine;
use MesCore\Config\Services\RoutingService;
use MesCore\Config\Services\BomService;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\ProductionPlan;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderMaterial;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderSheet;
use MesCore\Production\Models\WorkOrderSource;
use MesCore\Stock\Services\LotService;

/**
 * 작업지시 품목 줄 저장 · LOT · 사본 · 취소 — docs/screens/50-생산.md
 *   지시서( 머리 ) 단위 저장 · 채번 · 상태는 WorkOrderSheetService 가 부르고, 이 서비스는 줄 하나를 다룬다
 *   줄 번호 = 지시서 번호 + 순번 ( WO-YYMMDD-NNN-01 )
 *
 *   공정 : 화면이 보낸 순서 그대로 사본을 만든다. 라우팅에서 온 공정은 표준시간 · 선행 승인 조건을 라우팅 줄에서 다시 읽는다( 화면 값을 믿지 않음 )
 *   자재 : 대기 상태에서 저장할 때마다 사용 중 BOM 에서 다시 복사 ( 투입 지점은 같은 라우팅 줄을 가진 지시 공정으로 )
 *          출고된 자재가 있으면 다시 복사하지 않고 투입 지점 · 단순 소요만 맞춘다 ( 출고 줄이 자재 줄을 가리킨다 ) · 품목 변경 · 취소도 막는다
 *   LOT  : 새 지시 · 품목이나 LOT 규칙이 바뀐 대기 지시에 새로 발급 ( 앞 번호는 미사용으로 남긴다 — 결정 §9-7 )
 *   수주 : 연결 표로 분할 · 병합. 남은 지시 수량 초과는 경고만 ( 초과 생산 ). 연결은 바뀐 것만 고친다 ( 병합 이력 유지 )
 *   진행 중 : 날짜 · 공정 기한 · 작업자 · 비고만 ( 수량 · 공정 구성은 잠금 )
 *   라우팅 3단계 ( docs/라우팅-3단계-설계.md ) : 수주에서 온 지시는 수주 라우팅( ③ ) 공정 — 공정 순서가 다른 수주 줄은 한 지시에 못 담는다
 *     공정 변경 이력( scope WORK_ORDER ) : 처음 = CREATE + 불러온 라우팅과 다른 점 / 수정 = 바뀐 줄 · 공정 구성 + 표준시간 · 검사만
 *     투입 지점 : BOM 줄은 품목 라우팅( ② ) 줄을 가리킨다 → 지시 공정의 라우팅 줄 또는 그 원본 줄로 맞춘다
 *   자재 ( docs/대체자재-수주자재-설계.md ) : 수주에서 온 지시 = 첫 수주 줄의 수주 자재 / 재고 · 수동 = 품목 활성 BOM
 *     대체 자재도 행으로 복사 ( substitute_of_idx = 주 자재 행 · 투입 공정은 주 자재와 같다 )
 *     자재 구성이 다른 수주 줄은 한 지시에 못 담는다 ( 공정 순서와 같은 규칙 )
 */
class WorkOrderService {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

    // 화면( toForm )이 쓰는 관계
    public const FORM_RELATIONS = ['item', 'lot', 'ops.process', 'materials.childItem', 'materials.substitutes.childItem', 'sources.salesOrderLine.order', 'sources.creator', 'parent.item'];

    public function __construct(private LotService $lots, private RoutingService $routings, private BomService $boms) {}

    /**
     * 품목 줄 하나 저장. 새 줄은 $sheet( 저장된 지시서 )에 순번을 매겨 붙인다
     * @return array{0: WorkOrder, 1: string[]}
     */
    public function save(array $data, ?WorkOrder $wo, ?WorkOrderSheet $sheet = null): array {
        if ($wo && in_array($wo->status, ['DONE', 'CANCELED'], true)) {
            throw ValidationException::withMessages(['status' => [WorkOrder::STATUSES[$wo->status] . ' 상태의 작업지시는 고칠 수 없습니다.']]);
        }
        if ($wo && $wo->status === 'RUN') {
            return [$this->saveRunning($wo, $data), []];
        }

        $wo       = $wo ?? new WorkOrder();
        $errors   = [];
        $warnings = [];

        // 품목 : 제품 대분류 · 사용 중 ( 수정할 때 원래 품목이면 통과 )
        $item = Item::find($data['item_idx']);
        $sameItem = $wo->exists && (int) $wo->item_idx === (int) $data['item_idx'];
        if (!$item || (!$sameItem && ($item->item_type !== 'PRODUCT' || $item->is_use !== 'Y'))) {
            $errors['item_idx'] = ['작업지시할 수 있는 품목이 아닙니다. ( 제품 대분류의 사용 중 품목 )'];   // E_WO_ITEM_NOT_PRODUCT
        }

        // 출고된 자재가 있으면 품목을 바꿀 수 없다 ( 다른 제품의 자재가 지시에 남는다 )
        $issued = $wo->exists && $this->hasIssue($wo->work_order_idx);
        if ($issued && (int) $wo->item_idx !== (int) $data['item_idx']) {
            $errors['item_idx'] = ['출고된 자재가 있어 품목을 바꿀 수 없습니다. 자재출고에서 출고를 먼저 취소하세요.'];
        }

        // LOT 규칙 : 생산 기준 사용 중 ( LOT 을 쓸 때 )
        $rule = null;
        if ($this->lots->enabled()) {
            $rules = $this->lots->productionRules();
            $rule  = !empty($data['lot_rule_idx']) ? $rules->firstWhere('lot_rule_idx', (int) $data['lot_rule_idx']) : $rules->first();
            if (!$rule && !($wo->exists && $wo->lot_rule_idx && (int) $wo->lot_rule_idx === (int) ($data['lot_rule_idx'] ?? 0))) {
                $errors['lot_rule_idx'] = [$rules->isEmpty() ? 'LOT규칙관리에서 생산 기준( 생산일 · 종료일 ) LOT 규칙을 등록하세요.' : '사용 중인 생산 기준 LOT 규칙을 고르세요.'];
            }
        }

        // 공정
        $ops          = array_values($data['ops']);
        $processes    = Process::whereIn('process_idx', array_column($ops, 'process_idx'))->get()->keyBy('process_idx');
        $routingLines = RoutingLine::whereIn('routing_line_idx', array_filter(array_column($ops, 'routing_line_idx')))->get()->keyBy('routing_line_idx');
        $candidates   = ProcessEquipment::whereIn('process_idx', $processes->keys())->get()->groupBy('process_idx');
        foreach ($ops as $i => $op) {
            $proc = $processes->get($op['process_idx']);
            if (!$proc) {
                $errors["ops.{$i}.process_idx"] = ['공정을 찾을 수 없습니다.'];
                continue;
            }
            if (!empty($op['equipment_idx'])
                && !($candidates->get($proc->process_idx) ?? collect())->contains('equipment_idx', (int) $op['equipment_idx'])) {
                $errors["ops.{$i}.equipment_idx"] = ['그 공정의 설비 후보가 아닙니다.'];   // E_WO_EQUIP
            }
            if ($this->isIfl($proc) && !$this->yes($op['insp_first'] ?? 'N') && !$this->yes($op['insp_middle'] ?? 'N') && !$this->yes($op['insp_last'] ?? 'N')) {
                $errors["ops.{$i}.insp_first"] = ['초 · 중 · 종 중 하나는 켜야 합니다.'];   // E_WO_IFL_NONE
            }
        }

        // 수주 연결
        $sources = array_values($data['sources'] ?? []);
        $soLines = SalesOrderLine::with('order')->whereIn('sales_order_line_idx', array_column($sources, 'sales_order_line_idx'))->get()->keyBy('sales_order_line_idx');
        $sumSrc  = 0;
        foreach ($sources as $i => $s) {
            $line = $soLines->get($s['sales_order_line_idx']);
            if (!$line || !$line->order) {
                $errors["sources.{$i}.qty"] = ['수주 줄을 찾을 수 없습니다.'];
                continue;
            }
            if ((int) $line->item_idx !== (int) $data['item_idx']) {
                $errors["sources.{$i}.qty"] = ["{$line->order->order_no} 의 품목이 지시 품목과 다릅니다."];
                continue;
            }
            if ($line->order->status !== 'CONFIRMED' || $line->line_status === 'CANCELED') {
                $errors["sources.{$i}.qty"] = ["{$line->order->order_no} 는 확정 수주가 아닙니다."];
                continue;
            }
            $remain = $this->remainingQty($line, $wo->work_order_idx);
            if ((float) $s['qty'] > $remain) {
                $warnings[] = "{$line->order->order_no} #{$line->line_no} : 남은 지시 수량( " . $this->num(max(0, $remain)) . ' )보다 많이 담았습니다. ( 초과 생산 )';   // E_WO_SOURCE_OVER — 경고
            }
            $sumSrc += (float) $s['qty'];
        }
        // 공정 순서가 다른 수주 줄은 한 지시에 담지 않는다 ( 수주마다 공정이 다를 수 있다 — 첫 줄 기준 )
        if (count($sources) > 1) {
            $keys  = $this->routings->orderLineProcessKeys(array_column($sources, 'sales_order_line_idx'));
            $first = $keys[$sources[0]['sales_order_line_idx']] ?? '';
            foreach ($sources as $i => $s) {
                if ($i > 0 && ($keys[$s['sales_order_line_idx']] ?? '') !== $first && $line = $soLines->get($s['sales_order_line_idx'])) {
                    $errors["sources.{$i}.qty"] = ["{$line->order->order_no} #{$line->line_no} 는 첫 수주 줄과 공정이 달라 같이 담을 수 없습니다. 따로 지시하세요."];   // E_WO_SOURCE_ROUTING
                }
            }
        }
        // 자재 구성이 다른 수주 줄도 한 지시에 담지 않는다 ( 수주마다 자재가 다를 수 있다 — 첫 줄 기준 )
        if (count($sources) > 1) {
            $mkeys = $this->boms->orderLineMaterialKeys(array_column($sources, 'sales_order_line_idx'));
            $first = $mkeys[$sources[0]['sales_order_line_idx']] ?? '';
            foreach ($sources as $i => $s) {
                if ($i > 0 && !isset($errors["sources.{$i}.qty"]) && ($mkeys[$s['sales_order_line_idx']] ?? '') !== $first && $line = $soLines->get($s['sales_order_line_idx'])) {
                    $errors["sources.{$i}.qty"] = ["{$line->order->order_no} #{$line->line_no} 는 첫 수주 줄과 자재가 달라 같이 담을 수 없습니다. 따로 지시하세요."];   // E_WO_SOURCE_BOM
                }
            }
        }
        if ($sources && abs($sumSrc - (float) $data['target_qty']) > 0.000001) {
            $warnings[] = '수주 연결 수량 합( ' . $this->num($sumSrc) . ' )이 지시 수량과 다릅니다.';   // W_WO_SOURCE_SUM
        }
        $source = $sources ? 'ORDER' : (($data['source'] ?? 'MANUAL') === 'STOCK' ? 'STOCK' : 'MANUAL');

        // 출처 생산계획 ( 계획에서 만든 지시 ) : 품목이 같고 · 수주 연결은 그 계획에 담긴 수주 줄 안에서 · 취소된 계획은 안 됨 ( 이미 연결된 지시는 그대로 )
        $planIdx = !empty($data['production_plan_idx']) ? (int) $data['production_plan_idx'] : null;
        if ($planIdx && $planIdx !== (int) $wo->production_plan_idx) {
            $plan = ProductionPlan::find($planIdx);
            if (!$plan || $plan->status === 'CANCELED') {
                $errors['production_plan_idx'] = ['생산계획을 찾을 수 없거나 취소된 계획입니다.'];
            } elseif ((int) $plan->item_idx !== (int) $data['item_idx']) {
                $errors['production_plan_idx'] = ["{$plan->plan_no} 의 품목이 지시 품목과 다릅니다."];
            } elseif (!$sources || array_diff(array_map(fn ($s) => (int) $s['sales_order_line_idx'], $sources), $plan->sources()->pluck('sales_order_line_idx')->map(fn ($v) => (int) $v)->all())) {
                $errors['production_plan_idx'] = ["{$plan->plan_no} 에서 만든 지시는 그 계획에 담긴 수주 줄만 담습니다."];
            }
        }
        if ($planIdx && !isset($errors['production_plan_idx']) && ($plan ??= ProductionPlan::find($planIdx))) {
            $left = (float) $plan->qty - $plan->directedQty($wo->work_order_idx);
            if ((float) $data['target_qty'] > $left + 1e-9) {
                $warnings[] = "{$plan->plan_no} : 계획의 남은 수량( " . $this->num(max(0, $left)) . ' )보다 많이 지시합니다.';   // W_WO_OVER_PLAN
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 경고
        // 자재 원본 : 수주에서 온 지시 = 첫 수주 줄의 수주 자재 ( 없으면 품목 BOM ) / 재고 · 수동 = 품목 활성 BOM
        $bom = ($sources ? $this->boms->orderLineBom((int) $sources[0]['sales_order_line_idx']) : null)
            ?? Bom::itemBoms()->where('item_idx', $item->item_idx)->where('is_active', 'Y')->first();
        $bom?->load('lines.substitutes');
        if ($issued) {
            if ($bom?->bom_idx !== $wo->bom_idx) {
                $warnings[] = '출고된 자재가 있어 바뀐 BOM 을 다시 복사하지 않았습니다.';
            }
            $bom = null;
        } elseif (!$bom) {
            $warnings[] = '사용 중 BOM 이 없습니다. 자재 없는 지시로 저장합니다.';   // W_WO_NO_BOM
        }
        // 원자재출고 공정 없음 : 자재가 있는 지시면 알린다 ( 막지 않음 — 11-11 )
        //   출고는 자재출고 화면에서 따로 · 출고 전에도 첫 공정 진행 · 완료 때 덜 나간 자재 확인이 없다
        $hasMaterials = $issued || ($bom && $bom->lines->isNotEmpty());
        if ($hasMaterials && !$processes->contains(fn (Process $p) => $p->isRmOut())) {
            $warnings[] = '공정에 원자재출고가 없습니다. 자재는 자재출고 화면에서 따로 출고하세요. ( 출고 전에도 첫 공정을 진행할 수 있고, 덜 나간 자재를 확인하지 않습니다 )';   // W_WO_NO_RM_OUT
        }
        if ($data['due_date'] < $data['start_date']) {
            $warnings[] = '생산 기한이 착수 예정일보다 앞입니다.';   // W_WO_DUE
        } elseif ($data['due_date'] < now()->toDateString()) {
            $warnings[] = '생산 기한이 이미 지났습니다.';
        }

        if (!$wo->exists && !$sheet) {
            throw new \LogicException('새 품목 줄은 지시서가 있어야 합니다.');
        }

        // 이력 비교 기준 : 고치기 전 공정 ( 처음이면 불러온 라우팅 )
        $beforeOps = $wo->exists ? WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get() : null;

        $saved = DB::transaction(function () use ($wo, $sheet, $data, $item, $rule, $ops, $routingLines, $sources, $source, $bom, $issued, $beforeOps) {
            // 고치는 지시는 행을 잠근 뒤 다시 확인 ( 자재출고도 같은 행을 잠근다 — 위 검사와 저장 사이에 출고 · 상태 변경이 끼면 막는다 )
            //   끼었는데 그대로 저장하면 자재 줄을 다시 만들면서 출고 기록이 지워진 줄을 가리켰다
            if ($wo->exists) {
                $locked = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
                if (!$locked || $locked->status !== $wo->status || $this->hasIssue($wo->work_order_idx) !== $issued) {
                    throw ValidationException::withMessages(['status' => ['그 사이 다른 사람이 이 작업지시를 바꿨거나 자재를 출고했습니다. 다시 열어 저장하세요.']]);
                }
            }
            $isNew    = !$wo->exists;
            $newLot   = $isNew || (int) $wo->item_idx !== (int) $item->item_idx || ($rule && (int) $wo->lot_rule_idx !== (int) $rule->lot_rule_idx);

            $wo->fill([
                'source'      => $source,
                'item_idx'    => $item->item_idx,
                'routing_idx' => $data['routing_idx'] ?? null,
                'bom_idx'     => $issued ? $wo->bom_idx : $bom?->bom_idx,
                'target_qty'  => (string) $data['target_qty'],
                'scan_qty'    => (string) ($data['scan_qty'] ?? ($wo->exists ? $wo->scan_qty : 1)),
                'start_date'  => $data['start_date'],
                'due_date'    => $data['due_date'],
                'remark'      => $data['remark'] ?? null,
            ]);
            // 출처 생산계획 : 보낸 값 ( 없으면 그대로 — 수주에서 바로 만든 지시는 비어 있음 )
            if (array_key_exists('production_plan_idx', $data)) {
                $wo->production_plan_idx = $data['production_plan_idx'] ?: null;
            }
            if ($isNew) {
                $lineNo = (int) WorkOrder::where('sheet_idx', $sheet->sheet_idx)->lockForUpdate()->max('line_no') + 1;
                $wo->sheet_idx = $sheet->sheet_idx;
                $wo->line_no   = $lineNo;
                $wo->wo_no     = $sheet->sheet_no . '-' . str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT);
            }
            // LOT 방식 : 새 지시를 만들 때 한 번 정한다 ( 공정 LOT 추적 + LOT 사용이면 RUN — 이미 만든 지시는 그대로 · 실적LOT-설계 §9-5 )
            if ($isNew) {
                $wo->lot_mode = ($this->lots->enabled() && app(\MesCore\Basic\Services\PolicyService::class)->get('production.op_lot_tracking'))
                    ? WorkOrder::LOT_MODE_RUN : WorkOrder::LOT_MODE_WO;
            }
            if ($newLot && $rule) {
                if ($wo->isRunLot()) {
                    // RUN : 지시 LOT 는 발급하지 않는다 — 실적마다 ( 제품 LOT 는 이 규칙으로 · §9-1 · §9-2 )
                    $wo->lot_idx = null;
                } else {
                    $date = $rule->rule_type === 'PRODUCE_END' ? $data['due_date'] : $data['start_date'];
                    $lot  = $this->lots->issueProductionLot($item, $rule, $date);
                    $wo->lot_idx = $lot?->lot_idx;
                }
                $wo->lot_rule_idx = $rule->lot_rule_idx;
            }
            $wo->save();

            $opMap = $this->syncOps($wo, $ops, $routingLines);
            $issued ? $this->remapMaterials($wo, $opMap) : $this->copyMaterials($wo, $bom, $opMap);
            $this->syncSources($wo, $sources);
            $this->logOps($wo, $beforeOps, $data['routing_idx'] ?? null);

            return $wo;
        });

        return [$saved->fresh(self::FORM_RELATIONS), array_values(array_unique($warnings))];
    }

    // 대기 지시만 취소 — LOT 은 남는다( 미사용 ). 진행 중이면 3단계의 미달 종료로
    public function cancel(WorkOrder $wo): void {
        // 지시 행을 잠근 뒤 다시 읽어 판단 ( 자재출고도 같은 행을 잠근다 — 취소하는 순간 출고가 끼어들어 보유 자재가 남은 채 취소되지 않게 )
        DB::transaction(function () use ($wo) {
            $locked = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'WAIT') {
                $label = $locked ? WorkOrder::STATUSES[$locked->status] : '없는';
                throw ValidationException::withMessages(['status' => ["{$wo->wo_no} 는 {$label} 상태라 취소할 수 없습니다. ( 대기만 )"]]);
            }
            // 아직 지시가 들고 있는 자재( 출고 − 반납 > 0 )가 있을 때만 막는다 — 11-12 : 전에는 다 반납해도 출고한 적이 있으면 막았다
            if ($this->holdsMaterial($locked->work_order_idx)) {
                throw ValidationException::withMessages(['status' => ["{$wo->wo_no} 는 출고된 자재가 남아 있습니다. 자재출고에서 반납하거나 출고를 취소한 뒤 취소하세요."]]);
            }
            $locked->status = 'CANCELED';
            $locked->save();
            $wo->setRawAttributes($locked->getAttributes(), true);
        });
    }

    // 수주 줄의 남은 지시 수량 = 줄 수량 − 그 줄을 담은 지시들( 취소 제외 · 지금 고치는 지시 제외 )의 담은 수량
    public function remainingQty(SalesOrderLine $line, ?int $exceptWo = null): float {
        $directed = (float) WorkOrderSource::where('sales_order_line_idx', $line->sales_order_line_idx)
            ->when($exceptWo, fn ($q) => $q->where('work_order_idx', '!=', $exceptWo))
            ->whereHas('workOrder', fn ($q) => $q->where('status', '!=', 'CANCELED'))
            ->sum('qty');
        return (float) $line->qty - $directed;
    }

    // ---- 내부 ----

    // 진행 중 : 날짜 · 비고 · 공정별 기한 · 작업자만 ( 수량 · 공정 구성은 잠금 — E_WO_LOCKED )
    private function saveRunning(WorkOrder $wo, array $data): WorkOrder {
        if ((float) $data['target_qty'] != (float) $wo->target_qty || (int) $data['item_idx'] !== (int) $wo->item_idx) {
            throw ValidationException::withMessages(['target_qty' => ['진행 중인 작업지시는 품목 · 수량을 바꿀 수 없습니다.']]);
        }
        DB::transaction(function () use ($wo, $data) {
            $wo->fill(['start_date' => $data['start_date'], 'due_date' => $data['due_date'], 'remark' => $data['remark'] ?? null])->save();
            $byIdx = collect($data['ops'])->keyBy('work_order_op_idx');
            foreach ($wo->ops as $op) {
                if ($row = $byIdx->get($op->work_order_op_idx)) {
                    $op->fill(['due_date' => $row['due_date'] ?? null, 'worker_idx' => $row['worker_idx'] ?? null])->save();
                }
            }
        });
        return $wo->fresh(self::FORM_RELATIONS);
    }

    /** @return array<int,int> routing_line_idx → work_order_op_idx */
    private function syncOps(WorkOrder $wo, array $ops, $routingLines): array {
        $existing = WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->get()->keyBy('work_order_op_idx');
        $procs    = Process::whereIn('process_idx', array_column($ops, 'process_idx'))->get()->keyBy('process_idx');   // 한 번에 ( 전에는 공정마다 )
        $keep     = [];
        $map      = [];

        foreach ($ops as $n => $row) {
            $op = (!empty($row['work_order_op_idx']) && $existing->has($row['work_order_op_idx']))
                ? $existing->get($row['work_order_op_idx'])
                : new WorkOrderOp(['work_order_idx' => $wo->work_order_idx]);
            $rl   = !empty($row['routing_line_idx']) ? $routingLines->get($row['routing_line_idx']) : null;
            $proc = $procs->get($row['process_idx']);
            $ifl  = $this->isIfl($proc);

            $op->fill([
                'seq'                    => ($n + 1) * 10,
                'process_idx'            => $row['process_idx'],
                'routing_line_idx'       => $rl?->routing_line_idx,
                'equipment_idx'          => ($row['equipment_idx'] ?? null) ?: null,
                'worker_idx'             => ($row['worker_idx'] ?? null) ?: null,
                'cycle_time_sec'         => (string) ($rl?->cycle_time_sec ?? 0),
                'due_date'               => ($row['due_date'] ?? null) ?: null,
                'insp_first'             => $ifl && $this->yes($row['insp_first'] ?? 'N') ? 'Y' : 'N',
                'insp_middle'            => $ifl && $this->yes($row['insp_middle'] ?? 'N') ? 'Y' : 'N',
                'insp_last'              => $ifl && $this->yes($row['insp_last'] ?? 'N') ? 'Y' : 'N',
                // 선행 진행 승인 조건은 라우팅 원본에서 ( 화면 값을 믿지 않는다 )
                'pre_approve_min'        => (int) ($rl?->pre_approve_min ?? 0),
                'pre_approved_by_design' => $rl?->pre_approved_by_design === 'Y' ? 'Y' : 'N',
                'remark'                 => $row['remark'] ?? null,
            ])->save();

            $keep[] = $op->work_order_op_idx;
            if ($rl) {
                // BOM 투입 지점은 품목 라우팅( ② ) 줄 — 수주 라우팅( ③ ) 줄이면 그 원본 줄로도 찾게
                $map[$rl->routing_line_idx] = $op->work_order_op_idx;
                if ($rl->source_routing_line_idx) {
                    $map[$rl->source_routing_line_idx] ??= $op->work_order_op_idx;
                }
            }
        }

        WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->whereNotIn('work_order_op_idx', $keep ?: [0])->delete();
        return $map;
    }

    // 공정 변경 이력 ( 대기 지시 저장 ) — 처음 : CREATE + 불러온 라우팅과 다른 점 / 수정 : 바뀐 줄
    private function logOps(WorkOrder $wo, $beforeOps, ?int $routingIdx): void {
        $tracked = RoutingService::WO_TRACKED;
        $after   = WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get()->values();
        $scope   = RoutingChangeLog::SCOPE_WORK_ORDER;

        if ($beforeOps === null) {
            $base = $routingIdx ? RoutingLine::where('routing_idx', $routingIdx)->orderBy('seq')->get() : collect();
            $this->routings->logWhole('CREATE', $scope, $wo->work_order_idx, null, null, null, $after->toArray(), $routingIdx);
            $this->routings->logDiff($scope, $wo->work_order_idx, null,
                $base->map(fn ($l) => $this->routings->snapshot($l, $l->routing_line_idx, $tracked))->all(),
                $after->map(fn ($o, $i) => $this->routings->snapshot($o, $o->routing_line_idx, $tracked, $i + 1))->all(),
                $tracked);
            return;
        }
        $this->routings->logDiff($scope, $wo->work_order_idx, null,
            $beforeOps->values()->map(fn ($o, $i) => $this->routings->snapshot($o, $o->work_order_op_idx, $tracked, $i + 1))->all(),
            $after->map(fn ($o, $i) => $this->routings->snapshot($o, $o->work_order_op_idx, $tracked, $i + 1))->all(),
            $tracked);
    }

    // 자재 사본 — 사용 중 BOM 을 지시 수량으로 다시 펼친다. 투입 지점은 같은 라우팅 줄을 가진 지시 공정 ( 없으면 첫 수량 공정 = NULL )
    private function copyMaterials(WorkOrder $wo, ?Bom $bom, array $opMap): void {
        // 대체 자재 행 먼저 ( 주 자재 행을 가리키는 FK )
        WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->whereNotNull('substitute_of_idx')->delete();
        WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->delete();
        if (!$bom) {
            return;
        }
        $seq = 0;
        foreach ($bom->lines as $l) {
            $opIdx = $l->routing_line_idx ? ($opMap[$l->routing_line_idx] ?? null) : null;
            $main  = WorkOrderMaterial::create([
                'work_order_idx'    => $wo->work_order_idx,
                'seq'               => ++$seq,
                'child_item_idx'    => $l->child_item_idx,
                'bom_line_idx'      => $l->bom_line_idx,
                'work_order_op_idx' => $opIdx,
                'qty_per'           => (string) $l->qty,
                'yield_qty'         => $l->yield_qty !== null ? (string) $l->yield_qty : null,   // 생산 가능 수량 ( 11-12 — 소요 = 올림( 수량 ÷ N ) )
                'calc_code'         => $l->calc_code,
                'ext_n01'           => $l->ext_n01 !== null ? (string) $l->ext_n01 : null,
                'required_qty'      => $this->str(\MesCore\Support\MaterialNeed::of((float) $wo->target_qty, (float) $l->qty, $l->yield_qty)),
            ]);
            // 대체 자재 행 : 같은 BOM 줄 · 같은 투입 공정 · 자기 소요 ( 투입 지점을 다시 맞출 때도 주 자재와 같이 움직인다 )
            foreach ($l->substitutes as $sub) {
                WorkOrderMaterial::create([
                    'work_order_idx'          => $wo->work_order_idx,
                    'seq'                     => ++$seq,
                    'child_item_idx'          => $sub->child_item_idx,
                    'bom_line_idx'            => $l->bom_line_idx,
                    'substitute_of_idx'       => $main->work_order_material_idx,
                    'bom_line_substitute_idx' => $sub->bom_line_substitute_idx,
                    'work_order_op_idx'       => $opIdx,
                    'qty_per'                 => (string) $sub->qty,
                    'yield_qty'               => $sub->yield_qty !== null ? (string) $sub->yield_qty : null,
                    'calc_code'               => $sub->calc_code,
                    'ext_n01'                 => $sub->ext_n01 !== null ? (string) $sub->ext_n01 : null,
                    'required_qty'            => $this->str(\MesCore\Support\MaterialNeed::of((float) $wo->target_qty, (float) $sub->qty, $sub->yield_qty)),
                ]);
            }
        }
    }

    // 출고된 지시 : 자재 줄은 그대로 두고 투입 지점( 공정이 바뀌었을 수 있다 ) · 단순 소요( 수량이 바뀌었을 수 있다 )만
    //   대체 자재 행은 주 자재와 같은 BOM 줄을 가리키므로 같이 맞춰진다
    private function remapMaterials(WorkOrder $wo, array $opMap): void {
        $materials = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->get();
        $rlOf      = DB::table('bom_line')->whereIn('bom_line_idx', $materials->pluck('bom_line_idx')->filter())->pluck('routing_line_idx', 'bom_line_idx');
        foreach ($materials as $m) {
            $rl = $rlOf[$m->bom_line_idx] ?? null;
            $m->fill([
                'work_order_op_idx' => $rl ? ($opMap[$rl] ?? null) : null,
                'required_qty'      => $this->str(\MesCore\Support\MaterialNeed::of((float) $wo->target_qty, (float) $m->qty_per, $m->yield_qty)),
            ])->save();
        }
    }

    // 출고( 취소 제외 )가 한 번이라도 있는지
    // 출고한 적이 있는가 ( 반납했어도 — 출고 이력이 자재 줄에 붙어 있어 품목 변경 · 자재 다시 복사를 막는 기준 )
    private function hasIssue(int $woIdx): bool {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)->where('issued_qty', '>', 0)->exists();
    }

    // 지금 지시가 들고 있는 자재가 있는가 ( 출고 − 반납 > 0 ) — 지시 취소를 막는 기준
    private function holdsMaterial(int $woIdx): bool {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)->whereRaw('issued_qty - returned_qty > 0.000001')->exists();
    }

    // 병합 이력 유지 : 있던 연결은 담은 수량만 고치고( 수정자 · 수정일 ), 새 연결만 추가, 뺀 연결만 지운다
    private function syncSources(WorkOrder $wo, array $sources): void {
        $existing = WorkOrderSource::where('work_order_idx', $wo->work_order_idx)->get()->keyBy('sales_order_line_idx');
        $keep     = [];
        foreach ($sources as $s) {
            $row = $existing->get($s['sales_order_line_idx'])
                ?? new WorkOrderSource(['work_order_idx' => $wo->work_order_idx, 'sales_order_line_idx' => $s['sales_order_line_idx']]);
            $row->qty = (string) $s['qty'];
            if (!$row->exists || $row->isDirty('qty')) {
                $row->save();
            }
            $keep[] = (int) $s['sales_order_line_idx'];
        }
        WorkOrderSource::where('work_order_idx', $wo->work_order_idx)->whereNotIn('sales_order_line_idx', $keep ?: [0])->delete();
    }

    private function isIfl(?Process $p): bool {
        return $p && $p->process_type === Process::TYPE_INSPECTION && $p->inspection_kind === 'IFL';
    }

    private function yes($v): bool {
        return $v === 'Y' || $v === true || $v === 1 || $v === '1';
    }
}

<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\ProcessEquipment;
use MesCore\Config\Models\RoutingLine;
use MesCore\Order\Models\SalesOrderLine;
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
 */
class WorkOrderService {

    // 화면( toForm )이 쓰는 관계
    public const FORM_RELATIONS = ['item', 'lot', 'ops.process', 'materials.childItem', 'sources.salesOrderLine.order', 'sources.creator'];

    public function __construct(private LotService $lots) {}

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
        if ($sources && abs($sumSrc - (float) $data['target_qty']) > 0.000001) {
            $warnings[] = '수주 연결 수량 합( ' . $this->num($sumSrc) . ' )이 지시 수량과 다릅니다.';   // W_WO_SOURCE_SUM
        }
        $source = $sources ? 'ORDER' : (($data['source'] ?? 'MANUAL') === 'STOCK' ? 'STOCK' : 'MANUAL');

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 경고
        $bom = Bom::with('lines')->where('item_idx', $item->item_idx)->where('is_active', 'Y')->first();
        if ($issued) {
            if ($bom?->bom_idx !== $wo->bom_idx) {
                $warnings[] = '출고된 자재가 있어 바뀐 BOM 을 다시 복사하지 않았습니다.';
            }
            $bom = null;
        } elseif (!$bom) {
            $warnings[] = '사용 중 BOM 이 없습니다. 자재 없는 지시로 저장합니다.';   // W_WO_NO_BOM
        }
        if ($data['due_date'] < $data['start_date']) {
            $warnings[] = '생산 기한이 착수 예정일보다 앞입니다.';   // W_WO_DUE
        } elseif ($data['due_date'] < now()->toDateString()) {
            $warnings[] = '생산 기한이 이미 지났습니다.';
        }

        if (!$wo->exists && !$sheet) {
            throw new \LogicException('새 품목 줄은 지시서가 있어야 합니다.');
        }

        $saved = DB::transaction(function () use ($wo, $sheet, $data, $item, $rule, $ops, $routingLines, $sources, $source, $bom, $issued) {
            $isNew    = !$wo->exists;
            $newLot   = $isNew || (int) $wo->item_idx !== (int) $item->item_idx || ($rule && (int) $wo->lot_rule_idx !== (int) $rule->lot_rule_idx);

            $wo->fill([
                'source'      => $source,
                'item_idx'    => $item->item_idx,
                'routing_idx' => $data['routing_idx'] ?? null,
                'bom_idx'     => $issued ? $wo->bom_idx : $bom?->bom_idx,
                'target_qty'  => (string) $data['target_qty'],
                'scan_qty'    => (string) ($data['scan_qty'] ?? 1),
                'start_date'  => $data['start_date'],
                'due_date'    => $data['due_date'],
                'remark'      => $data['remark'] ?? null,
            ]);
            if ($isNew) {
                $lineNo = (int) WorkOrder::where('sheet_idx', $sheet->sheet_idx)->lockForUpdate()->max('line_no') + 1;
                $wo->sheet_idx = $sheet->sheet_idx;
                $wo->line_no   = $lineNo;
                $wo->wo_no     = $sheet->sheet_no . '-' . str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT);
            }
            if ($newLot && $rule) {
                $date = $rule->rule_type === 'PRODUCE_END' ? $data['due_date'] : $data['start_date'];
                $lot  = $this->lots->issueProductionLot($item, $rule, $date);
                $wo->lot_idx      = $lot?->lot_idx;
                $wo->lot_rule_idx = $rule->lot_rule_idx;
            }
            $wo->save();

            $opMap = $this->syncOps($wo, $ops, $routingLines);
            $issued ? $this->remapMaterials($wo, $opMap) : $this->copyMaterials($wo, $bom, $opMap);
            $this->syncSources($wo, $sources);

            return $wo;
        });

        return [$saved->fresh(self::FORM_RELATIONS), array_values(array_unique($warnings))];
    }

    // 대기 지시만 취소 — LOT 은 남는다( 미사용 ). 진행 중이면 3단계의 미달 종료로
    public function cancel(WorkOrder $wo): void {
        if ($wo->status !== 'WAIT') {
            throw ValidationException::withMessages(['status' => ["{$wo->wo_no} 는 " . WorkOrder::STATUSES[$wo->status] . ' 상태라 취소할 수 없습니다. ( 대기만 )']]);
        }
        if ($this->hasIssue($wo->work_order_idx)) {
            throw ValidationException::withMessages(['status' => ["{$wo->wo_no} 는 출고된 자재가 있습니다. 자재출고에서 출고를 먼저 취소하세요."]]);
        }
        $wo->status = 'CANCELED';
        $wo->save();
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
        $keep     = [];
        $map      = [];

        foreach ($ops as $n => $row) {
            $op = (!empty($row['work_order_op_idx']) && $existing->has($row['work_order_op_idx']))
                ? $existing->get($row['work_order_op_idx'])
                : new WorkOrderOp(['work_order_idx' => $wo->work_order_idx]);
            $rl   = !empty($row['routing_line_idx']) ? $routingLines->get($row['routing_line_idx']) : null;
            $proc = Process::find($row['process_idx']);
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
                $map[$rl->routing_line_idx] = $op->work_order_op_idx;
            }
        }

        WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->whereNotIn('work_order_op_idx', $keep ?: [0])->delete();
        return $map;
    }

    // 자재 사본 — 사용 중 BOM 을 지시 수량으로 다시 펼친다. 투입 지점은 같은 라우팅 줄을 가진 지시 공정 ( 없으면 첫 수량 공정 = NULL )
    private function copyMaterials(WorkOrder $wo, ?Bom $bom, array $opMap): void {
        WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->delete();
        if (!$bom) {
            return;
        }
        foreach ($bom->lines as $n => $l) {
            WorkOrderMaterial::create([
                'work_order_idx'    => $wo->work_order_idx,
                'seq'               => $n + 1,
                'child_item_idx'    => $l->child_item_idx,
                'bom_line_idx'      => $l->bom_line_idx,
                'work_order_op_idx' => $l->routing_line_idx ? ($opMap[$l->routing_line_idx] ?? null) : null,
                'qty_per'           => (string) $l->qty,
                'calc_code'         => $l->calc_code,
                'ext_n01'           => $l->ext_n01 !== null ? (string) $l->ext_n01 : null,
                'required_qty'      => $this->str((float) $l->qty * (float) $wo->target_qty),
            ]);
        }
    }

    // 출고된 지시 : 자재 줄은 그대로 두고 투입 지점( 공정이 바뀌었을 수 있다 ) · 단순 소요( 수량이 바뀌었을 수 있다 )만
    private function remapMaterials(WorkOrder $wo, array $opMap): void {
        $materials = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->get();
        $rlOf      = DB::table('bom_line')->whereIn('bom_line_idx', $materials->pluck('bom_line_idx')->filter())->pluck('routing_line_idx', 'bom_line_idx');
        foreach ($materials as $m) {
            $rl = $rlOf[$m->bom_line_idx] ?? null;
            $m->fill([
                'work_order_op_idx' => $rl ? ($opMap[$rl] ?? null) : null,
                'required_qty'      => $this->str((float) $m->qty_per * (float) $wo->target_qty),
            ])->save();
        }
    }

    // 출고( 취소 제외 )가 한 번이라도 있는지
    private function hasIssue(int $woIdx): bool {
        return DB::table('wo_stock')->where('work_order_idx', $woIdx)->where('issued_qty', '>', 0)->exists();
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

    private function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

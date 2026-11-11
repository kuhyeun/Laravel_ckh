<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderSheet;

/**
 * 작업지시서 저장 · 채번 · 취소 · 상태 ( 트랜잭션 경계 ) — docs/screens/50-생산.md "1단계 보완"
 *
 *   저장 : 지시서 + 품목 줄 전부를 한 트랜잭션. 줄은 WorkOrderService 가 저장( LOT · 사본 · 수주 연결 )하고
 *          착수 · 기한은 지시서 값을 줄에 복사한다. 줄 하나라도 틀리면 전부 되돌리고, 오류는 lines.{n}.* 로 돌려준다
 *          화면이 보내지 않은 저장된 줄은 그대로 둔다 ( 줄을 빼는 것은 [줄 취소] )
 *          반제품 줄의 부모( parent_work_order_idx — 어느 완제품 지시용 · 이력 ) : 저장된 줄 번호 또는 같이 보낸 줄의 순번( parent_line ) → 줄을 다 저장한 뒤 잇는다
 *   취소 : 줄 하나 = 대기 줄만 ( WorkOrderService::cancel ) / 지시서 = 취소 안 된 줄이 모두 대기일 때만 전부
 *   상태 : 줄에서 정해진다 — 모두 취소 → 취소 · 남은 줄이 모두 완료 → 완료 · 남은 줄이 모두 대기 → 대기 · 그 밖 → 진행
 */
class WorkOrderSheetService {

    public const NO_PREFIX = 'WO';

    public function __construct(private WorkOrderService $lines) {}

    /**
     * @return array{0: WorkOrderSheet, 1: string[]}
     */
    public function save(array $data, ?WorkOrderSheet $sheet): array {
        if ($sheet && in_array($sheet->status, ['DONE', 'CANCELED'], true)) {
            throw ValidationException::withMessages(['status' => [WorkOrder::STATUSES[$sheet->status] . ' 상태의 작업지시서는 고칠 수 없습니다.']]);
        }

        $warnings = [];
        if ($data['due_date'] < $data['start_date']) {
            $warnings[] = '생산 기한이 착수 예정일보다 앞입니다.';   // W_WO_DUE
        } elseif ($data['due_date'] < now()->toDateString()) {
            $warnings[] = '생산 기한이 이미 지났습니다.';
        }

        $saved = DB::transaction(function () use ($data, $sheet, &$warnings) {
            $sheet = $sheet ?? new WorkOrderSheet();
            $sheet->fill([
                'start_date'  => $data['start_date'],
                'due_date'    => $data['due_date'],
                'manager_idx' => $data['manager_idx'] ?? null,
                'remark'      => $data['remark'] ?? null,
            ]);
            if (!$sheet->exists) {
                $sheet->sheet_no = $this->nextNo(now());
            }
            $sheet->save();

            $existing = WorkOrder::where('sheet_idx', $sheet->sheet_idx)->get()->keyBy('work_order_idx');
            $errors   = [];
            $savedBy  = [];   // 보낸 순번 → 저장된 줄 ( 반제품 줄의 부모 찾기 )

            foreach (array_values($data['lines']) as $n => $line) {
                $wo = !empty($line['work_order_idx']) ? $existing->get($line['work_order_idx']) : null;
                if (!empty($line['work_order_idx']) && !$wo) {
                    $errors["lines.{$n}.item_idx"] = ['이 지시서의 품목 줄이 아닙니다.'];
                    continue;
                }
                // 완료 · 취소 줄은 건드리지 않는다
                if ($wo && in_array($wo->status, ['DONE', 'CANCELED'], true)) {
                    continue;
                }

                $lineData = array_merge($line, [
                    'start_date' => $data['start_date'],
                    'due_date'   => $data['due_date'],
                    'remark'     => $wo?->remark,
                ]);
                try {
                    [$savedLine, $lineWarnings] = $this->lines->save($lineData, $wo, $sheet);
                    $savedBy[$n] = $savedLine;
                    // 날짜 경고는 지시서에서 한 번만, 나머지는 줄 번호를 붙여서
                    foreach ($lineWarnings as $w) {
                        if (!str_starts_with($w, '생산 기한')) {
                            $warnings[] = "{$savedLine->wo_no} : {$w}";
                        }
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        $errors["lines.{$n}.{$key}"] = $messages;
                    }
                }
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            // 반제품 줄의 부모( 완제품 지시 ) — 부모가 뒤에 저장되므로 줄을 다 저장한 뒤에 잇는다 · 같은 지시서의 줄만
            $sheetLines = WorkOrder::where('sheet_idx', $sheet->sheet_idx)->pluck('work_order_idx')->map(fn ($v) => (int) $v)->flip();
            foreach (array_values($data['lines']) as $n => $line) {
                $child = $savedBy[$n] ?? null;
                if (!$child) continue;
                $parentIdx = isset($line['parent_line']) && isset($savedBy[(int) $line['parent_line']])
                    ? $savedBy[(int) $line['parent_line']]->work_order_idx
                    : ($line['parent_work_order_idx'] ?? null);
                if ($parentIdx !== null && (!$sheetLines->has((int) $parentIdx) || (int) $parentIdx === (int) $child->work_order_idx)) {
                    $errors["lines.{$n}.item_idx"] = ['반제품 줄의 완제품 줄을 찾을 수 없습니다. 다시 열어 주세요.'];
                    continue;
                }
                if ((int) $child->parent_work_order_idx !== (int) $parentIdx) {
                    $child->parent_work_order_idx = $parentIdx;
                    $child->save();
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            if (!WorkOrder::where('sheet_idx', $sheet->sheet_idx)->exists()) {
                throw ValidationException::withMessages(['lines' => ['품목 줄을 하나 이상 넣으세요.']]);
            }

            $this->refreshStatus($sheet);
            return $sheet;
        });

        return [$saved->fresh(['lines', 'manager']), array_values(array_unique($warnings))];
    }

    // 품목 줄 하나 취소 — 대기만 ( 출고된 자재가 있으면 막음 )
    //   $withSemi : 이 줄을 부모로 둔 반제품 지시( 아래로 전부 )도 함께 — 대기인 것만 취소 · 진행 · 완료는 그대로 두고 알린다
    //   하나라도 취소가 막히면( 출고된 자재 등 ) 전부 되돌린다 — 11-10 사용자 결정
    /** @return string[] 함께 취소한 반제품 지시 번호 · 남긴 것은 '( 진행 )' 표시 */
    public function cancelLine(WorkOrder $wo, bool $withSemi = false): array {
        return DB::transaction(function () use ($wo, $withSemi) {
            $this->lines->cancel($wo);
            $notes = [];
            if ($withSemi) {
                foreach ($this->semiDescendants($wo) as $child) {
                    if ($child->status === 'CANCELED') continue;
                    if ($child->status !== 'WAIT') {
                        $notes[] = "{$child->wo_no} ( " . WorkOrder::STATUSES[$child->status] . ' — 그대로 둠 )';
                        continue;
                    }
                    $this->lines->cancel($child);
                    $notes[] = $child->wo_no;
                    if ((int) $child->sheet_idx !== (int) $wo->sheet_idx) {
                        $this->refreshStatus($child->sheet);
                    }
                }
            }
            $this->refreshStatus($wo->sheet);
            return $notes;
        });
    }

    // 이 지시를 부모로 둔 반제품 지시 — 아래로 전부 ( 반제품의 반제품 · 순환 방어 )
    private function semiDescendants(WorkOrder $wo): array {
        $out  = [];
        $seen = [$wo->work_order_idx => true];
        $next = [$wo->work_order_idx];
        while ($next) {
            $children = WorkOrder::whereIn('parent_work_order_idx', $next)->get();
            $next = [];
            foreach ($children as $c) {
                if (isset($seen[$c->work_order_idx])) continue;
                $seen[$c->work_order_idx] = true;
                $out[]  = $c;
                $next[] = $c->work_order_idx;
            }
        }
        return $out;
    }

    // 지시서 취소 — 취소 안 된 줄이 모두 대기일 때만 전부
    public function cancelSheet(WorkOrderSheet $sheet): void {
        $open = $sheet->lines()->where('status', '!=', 'CANCELED')->get();
        $busy = $open->filter(fn (WorkOrder $w) => $w->status !== 'WAIT');
        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages(['status' => [
                "{$sheet->sheet_no} 에 " . WorkOrder::STATUSES[$busy->first()->status] . " 상태인 품목 줄( {$busy->first()->wo_no} )이 있어 지시서 전체를 취소할 수 없습니다. 대기 상태인 줄만 [줄 취소] 하세요.",
            ]]);
        }
        if ($open->isEmpty()) {
            throw ValidationException::withMessages(['status' => ["{$sheet->sheet_no} 는 이미 취소되었습니다."]]);
        }
        DB::transaction(function () use ($sheet, $open) {
            $open->each(fn (WorkOrder $w) => $this->lines->cancel($w));
            $this->refreshStatus($sheet);
        });
    }

    // 줄 상태로 지시서 상태를 다시 정한다 ( 생산 3단계에서 줄 상태가 바뀔 때도 부른다 )
    public function refreshStatus(WorkOrderSheet $sheet): void {
        $statuses = WorkOrder::where('sheet_idx', $sheet->sheet_idx)->pluck('status');
        $open     = $statuses->reject(fn ($s) => $s === 'CANCELED');

        $status = match (true) {
            $open->isEmpty()                                 => 'CANCELED',
            $open->every(fn ($s) => $s === 'DONE')           => 'DONE',
            $open->every(fn ($s) => $s === 'WAIT')           => 'WAIT',
            default                                          => 'RUN',
        };
        if ($sheet->status !== $status) {
            $sheet->status = $status;
            $sheet->save();
        }
    }

    // WO-YYMMDD-NNN ( 지시한 날 기준 )
    public function nextNo(Carbon $date): string {
        return \MesCore\Support\DocNumber::next(self::NO_PREFIX . '-' . $date->format('ymd') . '-', 'work_order_sheet', 'sheet_no');
    }
}

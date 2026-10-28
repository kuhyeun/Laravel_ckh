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
            if (!WorkOrder::where('sheet_idx', $sheet->sheet_idx)->exists()) {
                throw ValidationException::withMessages(['lines' => ['품목 줄을 하나 이상 넣으세요.']]);
            }

            $this->refreshStatus($sheet);
            return $sheet;
        });

        return [$saved->fresh(['lines', 'manager']), array_values(array_unique($warnings))];
    }

    // 품목 줄 하나 취소 — 대기만 ( 출고된 자재가 있으면 막음 )
    public function cancelLine(WorkOrder $wo): void {
        DB::transaction(function () use ($wo) {
            $this->lines->cancel($wo);
            $this->refreshStatus($wo->sheet);
        });
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
        $head = self::NO_PREFIX . '-' . $date->format('ymd') . '-';
        $last = WorkOrderSheet::where('sheet_no', 'like', $head . '%')->lockForUpdate()->orderByDesc('sheet_no')->value('sheet_no');
        $seq  = $last ? ((int) substr($last, strlen($head))) + 1 : 1;
        return $head . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}

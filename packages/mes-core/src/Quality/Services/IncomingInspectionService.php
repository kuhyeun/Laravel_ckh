<?php

namespace MesCore\Quality\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Purchase\Services\ReceiptService;
use MesCore\Quality\Models\Defect;
use MesCore\Quality\Models\Inspection;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 입고검사 ( QUA-01 · 입고관리에서도 같은 창 ) — 40-구매자재 §12
 *
 *   검사완료 : 합격 + 불합격 = 입고 수량. 합격분만 입고완료( 원장 + ), 불합격분은 불량 기록 ( 재고에 안 잡힘 )
 *   검사취소 : 합격분 되돌림( 역분개 ) → 다시 검사대기. 이미 출고 · 이동됐으면 막음
 *   Phase 1 은 SIMPLE 만 ( quality.mode.incoming )
 */
class IncomingInspectionService {

    public const REF_TYPE = 'stock_txn_line';

    public function __construct(private ReceiptService $receipts) {}

    /**
     * @param array{pass_qty:string, fail_qty:string, reason:?string, inspector_idx:int, inspected_at:string, remark:?string} $data
     */
    public function complete(StockTxnLine $line, array $data): Inspection {
        if ($line->line_status !== 'WAIT') {
            throw ValidationException::withMessages(['line' => ['검사대기 상태가 아닙니다. 목록을 새로고침하세요.']]);
        }

        $pass = (float) $data['pass_qty'];
        $fail = (float) ($data['fail_qty'] ?? 0);
        if (abs(($pass + $fail) - (float) $line->qty) > 0.000001) {
            throw ValidationException::withMessages(['pass_qty' => ['합격과 불합격을 더하면 입고 수량( ' . $this->num((float) $line->qty) . ' )이 되어야 합니다.']]);
        }
        if ($fail > 0 && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => ['불합격이 있으면 불량 사유를 넣으세요.']]);
        }

        $at = Carbon::parse($data['inspected_at']);
        if ($at->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['inspected_at' => ['아직 오지 않은 시각입니다.']]);
        }

        return DB::transaction(function () use ($line, $data, $pass, $fail, $at) {
            $line = StockTxnLine::whereKey($line->stock_txn_line_idx)->lockForUpdate()->first();
            if ($line->line_status !== 'WAIT') {
                throw ValidationException::withMessages(['line' => ['검사대기 상태가 아닙니다. 목록을 새로고침하세요.']]);
            }

            $inspection = Inspection::create([
                'insp_type'     => 'INCOMING',
                'ref_type'      => self::REF_TYPE,
                'ref_idx'       => $line->stock_txn_line_idx,
                'mode'          => 'SIMPLE',
                'result'        => $fail == 0 ? 'PASS' : ($pass == 0 ? 'FAIL' : 'PARTIAL'),
                'pass_qty'      => (string) $pass,
                'fail_qty'      => (string) $fail,
                'inspected_at'  => $at,
                'inspector_idx' => $data['inspector_idx'],
                'remark'        => $data['remark'] ?? null,
            ]);

            if ($fail > 0) {
                Defect::create([
                    'inspection_idx' => $inspection->inspection_idx,
                    'item_idx'       => $line->item_idx,
                    'lot_idx'        => $line->lot_idx,
                    'qty'            => (string) $fail,
                    'reason'         => mb_substr((string) $data['reason'], 0, 200),
                    'occurred_at'    => $at,
                ]);
            }

            $this->receipts->confirm($line, (string) $pass, (string) $fail, $at);
            $line->txn->refreshStatus();

            return $inspection;
        });
    }

    // 검사취소 — 합격분 되돌리고 다시 검사대기 ( 불량 기록은 지운다 — 검사가 없던 일이 되므로 )
    public function cancel(StockTxnLine $line): void {
        DB::transaction(function () use ($line) {
            $line = StockTxnLine::whereKey($line->stock_txn_line_idx)->lockForUpdate()->first();
            $inspection = $this->activeInspection($line);
            if ($line->line_status !== 'DONE' || !$inspection) {
                throw ValidationException::withMessages(['line' => ['검사완료된 줄이 아닙니다. 목록을 새로고침하세요.']]);
            }

            $this->receipts->unconfirm($line);

            $inspection->defects()->delete();
            $inspection->fill(['is_canceled' => 'Y'])->save();

            $line->fill(['line_status' => 'WAIT', 'pass_qty' => '0', 'fail_qty' => '0', 'done_at' => null])->save();
            $line->txn->refreshStatus();
        });
    }

    public function activeInspection(StockTxnLine $line): ?Inspection {
        return Inspection::with(['defects', 'inspector:account_idx,user_id,user_name'])
            ->where('ref_type', self::REF_TYPE)->where('ref_idx', $line->stock_txn_line_idx)
            ->where('is_canceled', 'N')->latest('inspection_idx')->first();
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

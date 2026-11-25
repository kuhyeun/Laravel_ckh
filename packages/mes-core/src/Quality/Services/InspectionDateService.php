<?php

namespace MesCore\Quality\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Production\Services\OpResultService;
use MesCore\Quality\Models\Defect;
use MesCore\Quality\Models\Inspection;
use MesCore\Stock\Services\StockService;
use MesCore\Support\DateEdit;

/**
 * 검사일시 수정 ( 11-25 ) — 저장한 검사의 검사일시만 고친다 ( 판정 · 수량 · 검사자 · 비고는 그대로 )
 *   - 취소된 검사는 못 고친다 · 미래( 지금 + 10분 넘게 )는 등록 때처럼 막는다
 *   - 같이 맞추는 것 : 그 검사가 만든 불량 ( defect.occurred_at — 등록 때 검사일시를 그대로 넣었다 )
 *   - 검사일시가 재고 원장 일시가 된 검사 ( 입고검사 합격분 · 마지막 공정 검사 회차 입고 · 외주 검사 실적 입고 ) 는
 *     moveStockLine() 으로 원장 · 입고 줄도 같이 옮기고 소급 규칙( StockService::checkBackdate )을 건다
 *   - 부르는 쪽이 트랜잭션을 연다 ( 검사 · 입고 줄은 잠그고 고친다 )
 */
class InspectionDateService {

    public function __construct(private StockService $stock) {}

    // 보낸 검사일시 ( 'inspected_at' 칸 ) — 형식 · 미래 차단
    public function read(mixed $value): Carbon {
        $at = DateEdit::dateTime($value, 'inspected_at', '검사일시');
        if ($at->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['inspected_at' => ['아직 오지 않은 시각입니다.']]);
        }
        return $at;
    }

    // 검사 기록을 잠그고 읽는다 — 없거나 취소 · 다른 종류면 422
    public function lock(?int $inspectionIdx, array $types): Inspection {
        $i = $inspectionIdx ? Inspection::whereKey($inspectionIdx)->lockForUpdate()->first() : null;
        if (!$i || !in_array($i->insp_type, $types, true)) {
            throw ValidationException::withMessages(['inspection' => ['고칠 검사 기록을 찾을 수 없습니다. 새로고침하세요.']]);
        }
        if ($i->is_canceled === 'Y') {
            throw ValidationException::withMessages(['inspection' => ['취소된 검사는 고칠 수 없습니다.']]);
        }
        return $i;
    }

    // 원장 일시가 바뀔 때 — 소급 경고( 없으면 null ) · 차단 일수를 넘으면 422
    public function checkLedger(Carbon $at): ?string {
        return $this->stock->checkBackdate($at, 'inspected_at');
    }

    // 검사일시 + 그 검사의 불량 발생일시 — 바꾸기 전 값은 활동 로그에 ( $before : 같이 옮긴 것 )
    public function move(Inspection $inspection, Carbon $at, array $before = []): void {
        DateEdit::remember(['inspection_idx' => $inspection->inspection_idx, 'inspected_at' => $inspection->inspected_at?->format('Y-m-d H:i:s')] + $before);
        $inspection->fill(['inspected_at' => $at])->save();
        Defect::where('inspection_idx', $inspection->inspection_idx)->update(['occurred_at' => $at]);
    }

    /**
     * 입고 줄 시각 옮기기 — 줄 완료 시각 · 그 줄의 원장( 되돌리지 않은 행 )
     *   생산 입고 ( 검사 회차 · 외주 실적이 만든 전표 — 줄 하나 ) 면 전표 발생일시 · LOT 입고 시각( 이 입고로 처음 잡혔을 때 )도
     *   구매 입고는 전표 · LOT 입고 시각 = 입고한 시각이라 그대로 ( 입고관리 몫 )
     * @return array 바꾸기 전 값 ( 활동 로그 )
     */
    public function moveStockLine(?int $lineIdx, Carbon $at): array {
        $line = $lineIdx ? DB::table('stock_txn_line')->where('stock_txn_line_idx', $lineIdx)->lockForUpdate()->first() : null;
        if (!$line || $line->line_status !== 'DONE') return [];
        $txn  = DB::table('stock_txn')->where('stock_txn_idx', $line->stock_txn_idx)->lockForUpdate()->first();
        $ts   = $at->format('Y-m-d H:i:s');

        DB::table('stock_txn_line')->where('stock_txn_line_idx', $lineIdx)->update(['done_at' => $ts, 'update_datetime' => now()]);
        DB::table('stock_ledger')->where('stock_txn_line_idx', $lineIdx)
            ->whereNull('reverses_idx')->whereNull('reversed_by_idx')
            ->update(['occurred_at' => $ts]);

        if ($txn && $txn->txn_type === OpResultService::TXN_TYPE) {
            DB::table('stock_txn')->where('stock_txn_idx', $txn->stock_txn_idx)->update(['occurred_at' => $ts, 'update_datetime' => now()]);
            if ($line->lot_idx && $line->done_at) {
                DB::table('lot')->where('lot_idx', $line->lot_idx)->where('in_at', $line->done_at)->update(['in_at' => $ts]);
            }
        }
        return ['stock_txn_line_idx' => (int) $lineIdx, 'done_at' => $line->done_at];
    }
}

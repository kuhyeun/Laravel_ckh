<?php

namespace MesCore\Quality\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Quality\Models\Inspection;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Shipment\Services\ShipmentService;

/**
 * 출고검사 ( 6003 · QUA-03 ) — docs/screens/70-품질.md §2 · 60-출하.md §0-1
 *
 *   대상 = 출하대기 줄 중 검사 정책( quality.shipping_inspection — 품목 범위 )이 켜진 줄. 결재를 쓰면 승인된 출하만
 *   판정은 줄 단위 [합격] / [불합격]. 불합격이면 불량 수량( ≤ 출하 수량 ) · 유형 · 상세 → 불량 기록 ( 재고는 건드리지 않는다 )
 *   불합격 줄은 출하 창에서 수량 · LOT 을 고쳐 저장하면 다시 검사대기 ( ShipmentService::save )
 *   검사 취소 : 출하 전만 — 검사 기록 · 불량은 취소 표시, 줄은 다시 검사대기
 */
class ShippingInspectionService {

    public const REF_TYPE = 'shipment_line';

    public function __construct(private ShipmentService $shipments, private DefectService $defects) {}

    // 지금 검사할 수 있는 줄인가 ( 출하대기 · 결재 통과 · 검사 대상 )
    public function inspectable(ShipmentLine $line): ?string {
        $s = $line->shipment;
        if ($s->status !== 'WAIT') return '출하대기 상태가 아닙니다.';
        if ($this->shipments->approvalRequired() && $s->approval_status !== 'APPROVED') return '결재 승인 뒤에 검사합니다.';
        if (!$this->shipments->inspectRequired((int) $line->item_idx)) return '출고검사 대상 품목이 아닙니다.';
        return null;
    }

    /**
     * @param array{result:string, fail_qty:?string, defect_type_code:?string, reason:?string, inspector_idx:int, inspected_at:string, remark:?string} $data
     */
    public function complete(ShipmentLine $line, array $data): Inspection {
        $at = Carbon::parse($data['inspected_at']);
        if ($at->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['inspected_at' => ['아직 오지 않은 시각입니다.']]);
        }
        $qty  = (float) $line->qty;
        $fail = $data['result'] === 'FAIL' ? (float) ($data['fail_qty'] ?? $qty) : 0.0;
        if ($data['result'] === 'FAIL' && ($fail <= 0 || $fail > $qty + 0.000001)) {
            throw ValidationException::withMessages(['fail_qty' => ['불량 수량은 0보다 크고 출하 수량( ' . $this->num($qty) . ' ) 이하여야 합니다.']]);
        }
        $this->defects->validate($fail, $data['defect_type_code'] ?? null, $data['reason'] ?? null);

        return DB::transaction(function () use ($line, $data, $fail, $qty, $at) {
            $line = ShipmentLine::with(['shipment', 'picks'])->whereKey($line->shipment_line_idx)->lockForUpdate()->first();
            if ($why = $this->inspectable($line)) {
                throw ValidationException::withMessages(['line' => [$why]]);
            }
            if (in_array($line->inspect_status, ['PASS', 'FAIL'], true)) {
                throw ValidationException::withMessages(['line' => ['이미 검사한 줄입니다. 목록을 새로고침하세요.']]);
            }

            $inspection = Inspection::create([
                'insp_type'     => 'SHIPPING',
                'ref_type'      => self::REF_TYPE,
                'ref_idx'       => $line->shipment_line_idx,
                'mode'          => 'SIMPLE',
                'result'        => $data['result'],
                'pass_qty'      => (string) ($qty - $fail),
                'fail_qty'      => (string) $fail,
                'inspected_at'  => $at,
                'inspector_idx' => $data['inspector_idx'],
                'remark'        => $data['remark'] ?? null,
            ]);
            $lots = $line->picks->pluck('lot_idx')->unique();
            $this->defects->record($inspection, [
                'item_idx'          => $line->item_idx,
                'lot_idx'           => $lots->count() === 1 ? (int) $lots->first() : 0,   // LOT 이 여럿이면 줄 단위로만
                'partner_idx'       => $line->shipment->partner_idx,
                'shipment_line_idx' => $line->shipment_line_idx,
                'qty'               => $fail,
                'defect_type_code'  => $data['defect_type_code'] ?? null,
                'reason'            => $data['reason'] ?? null,
                'occurred_at'       => $at,
            ]);

            $line->fill([
                'inspect_status' => $data['result'],
                'inspected_by'   => $data['inspector_idx'],
                'inspected_at'   => $at,
                'inspect_remark' => $data['result'] === 'FAIL' ? mb_substr((string) ($data['reason'] ?: $this->defects->typeName($data['defect_type_code'] ?? null)), 0, 200) : ($data['remark'] ?? null),
            ])->save();

            return $inspection;
        });
    }

    public function cancel(ShipmentLine $line): void {
        DB::transaction(function () use ($line) {
            $line = ShipmentLine::with('shipment')->whereKey($line->shipment_line_idx)->lockForUpdate()->first();
            if ($line->shipment->status !== 'WAIT' || !in_array($line->inspect_status, ['PASS', 'FAIL'], true)) {
                throw ValidationException::withMessages(['line' => ['출하 전 검사완료 줄만 취소할 수 있습니다.']]);
            }
            if ($inspection = $this->activeInspection($line)) {
                $this->defects->cancel($inspection);
                $inspection->fill(['is_canceled' => 'Y'])->save();
            }
            $line->fill(['inspect_status' => 'WAIT', 'inspected_by' => null, 'inspected_at' => null, 'inspect_remark' => null])->save();
        });
    }

    // 지금 판정의 근거 기록 ( 줄이 PASS / FAIL 일 때만 — 고쳐서 다시 대기가 되면 지난 기록은 보지 않는다 )
    public function activeInspection(ShipmentLine $line): ?Inspection {
        if (!in_array($line->inspect_status, ['PASS', 'FAIL'], true)) return null;
        return Inspection::with(['defects', 'inspector:account_idx,user_id,user_name'])
            ->where('ref_type', self::REF_TYPE)->where('ref_idx', $line->shipment_line_idx)
            ->where('is_canceled', 'N')->latest('inspection_idx')->first();
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

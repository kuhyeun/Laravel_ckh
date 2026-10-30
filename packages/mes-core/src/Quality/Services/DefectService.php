<?php

namespace MesCore\Quality\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Quality\Models\Defect;
use MesCore\Quality\Models\Inspection;

/**
 * 불량 기록 ( 입고 · 공정 · 출고 검사 공통 ) — docs/screens/70-품질.md §1
 *   유형 = 코드관리 DEFECT_TYPE ( 필수 ) · 상세 = reason ( 유형이 기타면 필수 )
 *   검사 취소 = 지우지 않고 is_canceled ( 6004 불량이력에 취소분으로 남는다 )
 */
class DefectService {

    public const TYPE_GROUP = 'DEFECT_TYPE';

    // 불합격이 있으면 유형 · 상세를 검사한다 ( 필드 이름은 화면 칸 그대로 )
    public function validate(float $failQty, ?string $typeCode, ?string $reason): void {
        if ($failQty <= 0) return;
        if (blank($typeCode) || !SystemCode::where('system_code_group_key', self::TYPE_GROUP)->where('system_code_key', $typeCode)->where('is_use', 'Y')->exists()) {
            throw ValidationException::withMessages(['defect_type_code' => ['불합격이 있으면 불량 유형을 고르세요.']]);
        }
        if ($typeCode === 'ETC' && blank($reason)) {
            throw ValidationException::withMessages(['reason' => ['불량 유형이 기타면 상세 내용을 넣으세요.']]);
        }
    }

    /**
     * @param array{item_idx:int, lot_idx?:int, partner_idx?:?int, work_order_op_idx?:?int, shipment_line_idx?:?int, qty:float, defect_type_code:string, reason:?string, occurred_at:Carbon} $d
     */
    public function record(Inspection $inspection, array $d): ?Defect {
        if ((float) $d['qty'] <= 0) return null;
        return Defect::create([
            'inspection_idx'    => $inspection->inspection_idx,
            // 초 · 중 · 종 단계( IFL_* )의 불량도 검사 종류는 공정 ( 불량이력의 입고 · 공정 · 출고 구분 )
            'insp_type'         => str_starts_with((string) $inspection->insp_type, 'IFL_') ? 'PROCESS' : $inspection->insp_type,
            'item_idx'          => $d['item_idx'],
            'lot_idx'           => $d['lot_idx'] ?? 0,
            'partner_idx'       => $d['partner_idx'] ?? null,
            'work_order_op_idx' => $d['work_order_op_idx'] ?? null,
            'shipment_line_idx' => $d['shipment_line_idx'] ?? null,
            'qty'               => (string) $d['qty'],
            'defect_type_code'  => $d['defect_type_code'],
            'reason'            => filled($d['reason'] ?? null) ? mb_substr((string) $d['reason'], 0, 200) : null,
            'occurred_at'       => $d['occurred_at'],
        ]);
    }

    public function cancel(Inspection $inspection): void {
        Defect::where('inspection_idx', $inspection->inspection_idx)->update(['is_canceled' => 'Y']);
    }

    public function typeName(?string $code): ?string {
        if (!$code) return null;
        return SystemCode::where('system_code_group_key', self::TYPE_GROUP)->where('system_code_key', $code)->value('system_code_name') ?? $code;
    }
}

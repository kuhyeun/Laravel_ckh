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

    // ---- 불량 여러 줄 ( 11-12 회의 — 행 추가로 입력 ) ----
    //   화면은 defects : [ { defect_type_code, qty, reason } ] — 줄 수량 합 = 불합격 수량
    //   defects 가 없으면 예전 한 줄 입력( defect_type_code · reason · 수량 = 불합격 )으로 본다

    /** @return array<int, array{defect_type_code:?string, qty:float, reason:?string}> */
    public function rowsOf(float $failQty, array $data): array {
        if (!empty($data['defects']) && is_array($data['defects'])) {
            return array_values(array_map(fn ($r) => [
                'defect_type_code' => $r['defect_type_code'] ?? null,
                'qty'              => (float) ($r['qty'] ?? 0),
                'reason'           => $r['reason'] ?? null,
            ], $data['defects']));
        }
        return [['defect_type_code' => $data['defect_type_code'] ?? null, 'qty' => $failQty, 'reason' => $data['reason'] ?? null]];
    }

    // 불합격이 있으면 줄마다 유형 · 수량 · ( 기타면 ) 상세 · 줄 합 = 불합격
    public function validateRows(float $failQty, array $data): void {
        if ($failQty <= 0) return;
        if (empty($data['defects'])) {
            $this->validate($failQty, $data['defect_type_code'] ?? null, $data['reason'] ?? null);
            return;
        }
        $types  = SystemCode::where('system_code_group_key', self::TYPE_GROUP)->where('is_use', 'Y')->pluck('system_code_key')->all();
        $errors = [];
        $sum    = 0.0;
        foreach ($this->rowsOf($failQty, $data) as $i => $r) {
            if (blank($r['defect_type_code']) || !in_array($r['defect_type_code'], $types, true)) $errors["defects.{$i}.defect_type_code"] = ['불량 유형을 고르세요.'];
            if ($r['qty'] <= 0)                                                                    $errors["defects.{$i}.qty"]              = ['수량을 넣으세요.'];
            if ($r['defect_type_code'] === 'ETC' && blank($r['reason']))                          $errors["defects.{$i}.reason"]           = ['기타면 상세를 넣으세요.'];
            $sum += $r['qty'];
        }
        if (!$errors && abs($sum - $failQty) > 0.000001) {
            $errors['defects'] = ['불량 줄 수량 합( ' . $this->num($sum) . ' )이 불합격 수량( ' . $this->num($failQty) . ' )과 같아야 합니다.'];
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    /** 줄마다 불량 1건 — $base : 품목 · LOT · 거래처 · 공정 · 출하 줄 · 발생 일시 ( record() 의 줄 값 빼고 ) */
    public function recordRows(Inspection $inspection, array $base, float $failQty, array $data): void {
        if ($failQty <= 0) return;
        foreach ($this->rowsOf($failQty, $data) as $r) {
            $this->record($inspection, $base + $r);
        }
    }

    // 화면 요약 : '치수 3 · 외관 2' ( 줄이 하나면 유형만 ) · 상세는 ' / ' 로 잇는다
    public function summary($defects): array {
        $rows = collect($defects)->values();
        if ($rows->isEmpty()) return ['defect_type' => null, 'reason' => null];
        $names = SystemCode::where('system_code_group_key', self::TYPE_GROUP)->pluck('system_code_name', 'system_code_key');
        $type  = $rows->count() === 1
            ? ($names[$rows[0]->defect_type_code] ?? $rows[0]->defect_type_code)
            : $rows->map(fn ($d) => ($names[$d->defect_type_code] ?? $d->defect_type_code) . ' ' . $this->num((float) $d->qty))->implode(' · ');
        return ['defect_type' => $type, 'reason' => $rows->pluck('reason')->filter()->implode(' / ') ?: null];
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    public function cancel(Inspection $inspection): void {
        Defect::where('inspection_idx', $inspection->inspection_idx)->update(['is_canceled' => 'Y']);
    }

    public function typeName(?string $code): ?string {
        if (!$code) return null;
        return SystemCode::where('system_code_group_key', self::TYPE_GROUP)->where('system_code_key', $code)->value('system_code_name') ?? $code;
    }
}

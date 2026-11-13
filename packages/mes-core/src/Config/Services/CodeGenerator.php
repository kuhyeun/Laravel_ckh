<?php

namespace MesCore\Config\Services;

use Illuminate\Support\Facades\DB;

/**
 * 품번 · 거래처코드 자동 생성 ( 11-11 회의 '거래처, 품목 코드 자동생성 / 필수값 제거' · 11-12 )
 *   등록 화면에서 코드를 비우고 저장하면 머리글자 + 일련번호 4자리 ( 예 : MT-0001 · PD-0012 · CU-0003 )
 *   · 품목   : 자재 MT · 반제품 SM · 그 밖의 제품 PD
 *   · 거래처 : 고객사 CU · ( 고객사가 아니면 ) 구매처 SU · 외주처 SC · 모두 아니면 PT
 *   번호 = 같은 머리글자의 "머리글자-숫자" 코드 중 가장 큰 숫자 + 1 ( 손으로 넣은 MT-02 가 있으면 MT-0003 부터 ) · 이미 있는 코드는 건너뛴다
 *   직접 넣은 코드는 그대로 쓴다 · 수정 화면 · 엑셀 일괄 등록( 코드가 열쇠 )은 지금처럼 코드 필수
 */
class CodeGenerator {

    private const DIGITS = 4;

    public static function item(?string $type, ?string $kind): string {
        $prefix = $type === 'MATERIAL' ? 'MT' : ($kind === 'SEMI' ? 'SM' : 'PD');
        return self::next('item', 'item_code', $prefix);
    }

    /** @param array{is_customer?:?string, is_supplier?:?string, is_subcon?:?string} $roles Y / N */
    public static function partner(array $roles): string {
        $prefix = ($roles['is_customer'] ?? 'N') === 'Y' ? 'CU'
            : (($roles['is_supplier'] ?? 'N') === 'Y' ? 'SU'
            : (($roles['is_subcon'] ?? 'N') === 'Y' ? 'SC' : 'PT'));
        return self::next('partner', 'partner_code', $prefix);
    }

    private static function next(string $table, string $column, string $prefix): string {
        $max = 0;
        foreach (DB::table($table)->where($column, 'like', "{$prefix}-%")->pluck($column) as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        do {
            $code = sprintf('%s-%0' . self::DIGITS . 'd', $prefix, ++$max);
        } while (DB::table($table)->where($column, $code)->exists());
        return $code;
    }
}

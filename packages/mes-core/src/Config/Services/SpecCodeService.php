<?php

namespace MesCore\Config\Services;

use Illuminate\Support\Facades\Auth;
use MesCore\Basic\Models\SystemCode;
use MesCore\Config\Models\Item;

// 규격 코드 ( 코드관리 그룹 SPEC ) — 품목 규격의 기준 목록 ( docs/품목-규격구분-설계.md §5 )
//   코드키 = 규격 비교 값( Item::specKey — 공백 · 대소문자 · x/× 무시 ) → 같은 규격이 두 번 등록되지 않는다 · 코드명 = 입력한 그대로
//   품목 규격은 목록에 없어도 넣을 수 있다 → 품목 저장 · 엑셀 등록 · 새 자재 등록 때 없는 규격은 여기에 자동으로 더한다
class SpecCodeService {

    public const GROUP = 'SPEC';

    // 없으면 더한다 ( 이미 있으면 그대로 — 미사용이어도 다시 켜지 않는다 ) → 그 코드
    public function ensure(?string $spec): ?SystemCode {
        $spec = trim((string) $spec);
        if ($spec === '') {
            return null;
        }
        $key = Item::specKey($spec);
        return SystemCode::where('system_code_group_key', self::GROUP)->where('system_code_key', $key)->first()
            ?? SystemCode::create([
                'system_code_group_key' => self::GROUP,
                'system_code_key'       => $key,
                'system_code_name'      => mb_substr($spec, 0, 200),
                'is_use'                => 'Y',
                'sort_order'            => min(9999, (int) SystemCode::where('system_code_group_key', self::GROUP)->max('sort_order') + 1),
                'create_account_idx'    => Auth::id() ?? 0,
            ]);
    }
}

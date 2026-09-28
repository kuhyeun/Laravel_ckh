<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;

// 업무 화면용 공통코드 조회 ( 로그인 사용자 누구나 )
//   코드관리( basic.code.* )는 관리 화면 전용이고, 드롭다운 등 조회는 이 API 를 사용한다
class CommonCodeController extends Controller {

    // GET /common/codes?groups[]=UNIT&groups[]=PARTNER_CONTACT_TYPE
    //   → { codes: { UNIT: [ { key, name, value, is_use } ], PARTNER_CONTACT_TYPE: [ ... ] } }
    //   요청한 그룹은 코드가 없어도 빈 배열로 내려준다 ( 프론트에서 undefined 처리 불필요 )
    //   기본은 사용 중( is_use = Y )만. with_inactive=1 이면 미사용 코드도 포함
    //     ( 과거 데이터 조회 화면에서 내려간 코드의 이름을 표시해야 할 때 )
    public function codes(Request $request) {
        $validated = $request->validate([
            'groups'        => 'required|array|min:1|max:20',
            'groups.*'      => 'required|string|max:100',
            'with_inactive' => 'sometimes|boolean',
        ]);

        $groups = array_values(array_unique($validated['groups']));

        $rows = SystemCode::select('system_code_group_key', 'system_code_key', 'system_code_name', 'system_code_value', 'is_use')
            ->whereIn('system_code_group_key', $groups)
            ->when(!($validated['with_inactive'] ?? false), fn ($q) => $q->where('is_use', 'Y'))
            ->orderBy('system_code_group_key')
            ->orderBy('sort_order')
            ->orderBy('system_code_key')
            ->get()
            ->groupBy('system_code_group_key');

        $codes = [];
        foreach ($groups as $group) {
            $codes[$group] = ($rows[$group] ?? collect())->map(fn ($c) => [
                'key'    => $c->system_code_key,
                'name'   => $c->system_code_name,
                'value'  => $c->system_code_value,
                'is_use' => $c->is_use,
            ])->values();
        }

        return response()->json(['codes' => $codes]);
    }
}

<?php

namespace MesCore\Basic\Controllers;

use Illuminate\Http\Request;
use MesCore\Config\Models\Item;
use MesCore\Http\Controller;

// 품목 고르기( ItemPicker 서버 모드 ) 공용 검색 — 로그인만 ( 여러 화면이 같이 쓴다 · 조회만 )
//   30개씩 ( 목록 끝까지 스크롤하면 다음 30개 — offset ) · 찾기 = 낱말이 모두 품번 · 품명 · 규격 어딘가에 ( 규격은 공백 · 대소문자 · x/× 무시 )
//   code 를 주면 그 품번만( 정확히 — 같은 품번의 규격들 : 수주 [자재] 창 규격 칸 )
//   ids 를 주면 그 품목만 ( 고른 값의 이름 표시 — 다른 거르기는 보지 않는다 )
//   extra : 화면이 고른 뒤 쓰는 칸만 허용 ( 단가 · 포장 수량 · 라우팅 )
class ItemSearchController extends Controller {

    private const LIMIT = 30;
    private const EXTRA = ['std_sale_price', 'std_buy_price', 'pack_qty', 'routing_idx'];

    public function search(Request $request) {
        $v = $request->validate([
            'q'                => 'nullable|string|max:100',
            'code'             => 'nullable|string|max:40',
            'types'            => 'nullable|array',
            'types.*'          => 'in:MATERIAL,PRODUCT',
            'exclude'          => 'nullable|array|max:50',
            'exclude.*'        => 'integer',
            'ids'              => 'nullable|array|max:50',
            'ids.*'            => 'integer',
            'extra'            => 'nullable|array',
            'extra.*'          => 'in:' . implode(',', self::EXTRA),
            'include_inactive' => 'nullable|boolean',
            'offset'           => 'nullable|integer|min:0',
            'limit'            => 'nullable|integer|min:1|max:50',
        ]);
        $extra = $v['extra'] ?? [];

        if (!empty($v['ids'])) {
            return response()->json(['rows' => Item::forPicker($extra)->whereIn('item_idx', $v['ids'])->get(), 'has_more' => false]);
        }

        $limit  = (int) ($v['limit'] ?? self::LIMIT);
        $offset = (int) ($v['offset'] ?? 0);
        $words  = array_values(array_filter(preg_split('/\s+/u', trim((string) ($v['q'] ?? '')))));

        $rows = Item::forPicker($extra)
            ->when(empty($v['include_inactive']), fn ($q) => $q->where('is_use', 'Y'))
            ->when(!empty($v['types']), fn ($q) => $q->whereIn('item_type', $v['types']))
            ->when(isset($v['code']) && $v['code'] !== '', fn ($q) => $q->where('item_code', $v['code']))
            ->when(!empty($v['exclude']), fn ($q) => $q->whereNotIn('item_idx', $v['exclude']))
            ->when($words, function ($q) use ($words) {
                foreach ($words as $w) {
                    $like = '%' . addcslashes($w, '%_\\') . '%';
                    $key  = '%' . addcslashes(Item::specKey($w), '%_\\') . '%';
                    $q->where(fn ($o) => $o->where('item_code', 'like', $like)->orWhere('item_name', 'like', $like)
                        ->orWhere('spec', 'like', $like)->orWhere('spec_key', 'like', $key));
                }
            })
            ->orderByRaw("item_type = 'MATERIAL' DESC")   // 자재 · 제품이 섞이면 자재 먼저 ( 발주 · 입고 · BOM )
            ->orderBy('item_code')->orderBy('item_idx')
            ->offset($offset)->limit($limit + 1)
            ->get();

        return response()->json(['rows' => $rows->take($limit)->values(), 'has_more' => $rows->count() > $limit]);
    }
}

<?php

namespace MesCore\Stock\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 재고 화면의 품목 범위 ( 자재관리 = MATERIAL · 제품관리 = PRODUCT ) — 라우트가 정한다
//   컨트롤러는 ScopesItemType 으로 읽어 목록을 거르고, 처리 · 취소할 대상이 범위 밖이면 막는다
//   화면이 보낸 item_type 은 믿지 않는다 ( 범위가 있으면 덮어쓴다 )
class ItemScope {
    public function handle(Request $request, Closure $next, string $itemType): Response {
        $request->attributes->set('item_scope', $itemType);
        return $next($request);
    }
}

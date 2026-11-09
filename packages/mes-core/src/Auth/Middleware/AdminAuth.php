<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth {
    use HandlesUnauthenticated;

    public function handle(Request $request, Closure $next): Response {
        // 세션 만료 → XHR/Inertia 는 401, 그 외는 로그인으로 리다이렉트
        $user = $this->activeUser($request);   // DB 의 레벨 · 사용 여부 ( 세션 값이 아니라 )
        if( !$user ) {
            return $this->unauthenticated($request);
        }

        // 권한 부족 → 403 ( abort 로 던져 Inertia 에러 페이지가 렌더되도록 )
        if( (int) $user->user_level > 1 ) {
            abort( 403, 'Permission Denied.' );
        }

        return $next($request);
    }
}

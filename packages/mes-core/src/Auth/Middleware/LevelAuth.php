<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LevelAuth {
    use HandlesUnauthenticated;

    public function handle(Request $request, Closure $next, string $maxLevel): Response {
        // 세션 만료 → XHR/Inertia 는 401, 그 외는 로그인으로 리다이렉트
        if( !$request->session()->has('user_id') ) {
            return $this->unauthenticated($request);
        }

        // 권한 부족 → 403 ( abort 로 던져 Inertia 에러 페이지가 렌더되도록 )
        if( (int)$request->session()->get('user_level') > (int)$maxLevel ) {
            abort( 403, 'Permission Denied.' );
        }

        return $next($request);
    }
}

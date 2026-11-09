<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAuth {
    use HandlesUnauthenticated;

    public function handle(Request $request, Closure $next): Response {
        // 세션 만료 → XHR/Inertia 는 401, 그 외는 로그인으로 리다이렉트
        if( !$this->activeUser($request) ) {   // 지워졌거나 미사용 계정이면 로그아웃
            return $this->unauthenticated($request);
        }

        return $next($request);
    }
}

<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth {

    public function handle(Request $request, Closure $next): Response {
        // 세션 만료 → 로그인으로 리다이렉트
        if( !$request->session()->has('user_id') ) {
            if( $request->header('X-Inertia') ) {
                return response()->json(['message' => 'Unauthenticated.'], 409)
                    ->header('X-Inertia-Location', route('user.login'));
            }
            return redirect()->route('user.login');
        }

        // 권한 부족 → 403 ( abort 로 던져 Inertia 에러 페이지가 렌더되도록 )
        if( $request->session()->get('user_level') > 1 ) {
            abort( 403, 'Permission Denied.' );
        }

        return $next($request);
    }
}

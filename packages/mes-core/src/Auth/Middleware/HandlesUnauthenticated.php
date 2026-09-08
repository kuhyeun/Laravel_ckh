<?php

namespace MesCore\Auth\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 세션 만료(미인증) 공통 응답
 *
 * - XHR / Inertia 요청 → 401 JSON
 *   ( 프론트에서 SweetAlert 안내 후 로그인 페이지로 이동시키기 위함.
 *     409 + X-Inertia-Location 을 쓰면 Inertia 가 즉시 하드 리다이렉트해서 안내창을 띄울 틈이 없음 )
 * - 일반 브라우저 요청 → 로그인 페이지로 리다이렉트
 */
trait HandlesUnauthenticated {

    protected function unauthenticated(Request $request): Response {
        if ($request->header('X-Inertia') || $request->ajax() || $request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('user.login');
    }
}

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

    /**
     * 세션의 계정을 DB 에서 다시 확인 ( 11-09 코드 검사 A-1 )
     *   지워졌거나 미사용이면 로그아웃 → null / 레벨이 바뀌었으면 세션 값도 맞춘다
     *   ( 전에는 로그인 때 넣은 세션 레벨만 봐서, 관리자를 내리거나 미사용으로 바꿔도 로그아웃 전까지 관리 화면이 열렸다 )
     *   Auth::user() 는 요청마다 한 번 읽는다 ( 권한 확인도 같은 값을 쓴다 )
     */
    protected function activeUser(Request $request): ?\MesCore\Auth\Models\User {
        if (!$request->session()->has('user_id')) {
            return null;
        }
        /** @var \MesCore\Auth\Models\User|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user || $user->is_use !== true) {
            \Illuminate\Support\Facades\Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return null;
        }
        if ((int) $request->session()->get('user_level') !== (int) $user->user_level) {
            $request->session()->put('user_level', $user->user_level);
            $request->session()->put('user_type', $user->user_type);
        }
        return $user;
    }

    protected function unauthenticated(Request $request): Response {
        if ($request->header('X-Inertia') || $request->ajax() || $request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('user.login');
    }
}

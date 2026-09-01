<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Menu\Services\MenuPermissionService;
use Symfony\Component\HttpFoundation\Response;

class MenuAuth {

    public function __construct(private MenuPermissionService $menuPermissionService) {}

    public function handle(Request $request, Closure $next, string $menuCode, string $action = 'can_read'): Response {
        // 세션 만료 → 로그인으로 리다이렉트
        if( !$request->session()->has('user_id') ) {
            if( $request->header('X-Inertia') ) {
                return response()->json(['message' => 'Unauthenticated.'], 409)
                    ->header('X-Inertia-Location', route('user.login'));
            }
            return redirect()->route('user.login');
        }

        /** @var User|null $user */
        $user = Auth::user();
        if( $user === null || !$this->menuPermissionService->can($user, $menuCode, $action) ) {
            return response( 'Permission Denied.', 403 );
        }

        return $next($request);
    }
}

<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use Symfony\Component\HttpFoundation\Response;

class MenuAuth {
    use HandlesUnauthenticated;

    public function __construct(private MenuPermissionService $menuPermissionService) {}

    /**
     * @param string $menuRef 권한을 검사할 메뉴 참조.
     *   - 'Basic/CodeManage' 처럼 '/' 가 있으면 page_component 로 보고 menu_code 를 조회한다.
     *     ( menu_code 는 관리화면에서 변경될 수 있으므로, 파일 위치라 사실상 불변인 page_component 를 앵커로 사용 )
     *   - '/' 가 없으면 menu_code 그대로 사용한다 ( 하위호환 ).
     */
    public function handle(Request $request, Closure $next, string $menuRef, string $action = 'can_read'): Response {
        // 세션 만료 → XHR/Inertia 는 401, 그 외는 로그인으로 리다이렉트
        if( !$request->session()->has('user_id') ) {
            return $this->unauthenticated($request);
        }

        $menuCode = $this->resolveMenuCode($menuRef);

        /** @var User|null $user */
        $user = Auth::user();
        if( $user === null || $menuCode === null || !$this->menuPermissionService->can($user, $menuCode, $action) ) {
            return response( 'Permission Denied.', 403 );
        }

        return $next($request);
    }

    // page_component( '/' 포함 ) → menu_code 조회. 그 외엔 menu_code 로 간주.
    private function resolveMenuCode(string $menuRef): ?string {
        if( !str_contains($menuRef, '/') ) {
            return $menuRef;
        }

        return Menu::where('page_component', $menuRef)->value('menu_code');
    }
}

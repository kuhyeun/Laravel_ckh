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
     *   같은 화면을 쓰는 메뉴가 여럿이면( 입고관리 · 입고이력 ) 하나라도 허용하면 통과
     *     조회 전용 메뉴는 쓰기 · 삭제 권한이 늘 없으므로( MenuPermissionService ) 쓰기 API 는 원래 메뉴 권한으로만 통과한다
     */
    public function handle(Request $request, Closure $next, string $menuRef, string $action = 'can_read'): Response {
        // 세션 만료 → XHR/Inertia 는 401, 그 외는 로그인으로 리다이렉트
        if( !$this->activeUser($request) ) {   // 지워졌거나 미사용 계정이면 로그아웃
            return $this->unauthenticated($request);
        }

        $menuCodes = $this->resolveMenuCodes($menuRef);

        /** @var User|null $user */
        $user    = Auth::user();
        $allowed = $user !== null && collect($menuCodes)->contains(fn ($code) => $this->menuPermissionService->can($user, (string) $code, $action));
        if( !$allowed ) {
            return response( 'Permission Denied.', 403 );
        }

        return $next($request);
    }

    // page_component( '/' 포함 ) → 그 화면을 쓰는 메뉴 코드 전부. 그 외엔 menu_code 로 간주.
    private function resolveMenuCodes(string $menuRef): array {
        if( !str_contains($menuRef, '/') ) {
            return [$menuRef];
        }

        return $this->menuPermissionService->pageCodes($menuRef);   // 캐시 ( 요청마다 메뉴 표를 찾지 않는다 )
    }
}

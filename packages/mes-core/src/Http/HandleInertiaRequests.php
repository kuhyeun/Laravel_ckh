<?php

namespace MesCore\Http;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemConfig;
use MesCore\Menu\Services\MenuPermissionService;

class HandleInertiaRequests extends Middleware {

    public function __construct(private MenuPermissionService $menuPermissionService) {}

    public function share(Request $request): array {
        $payload = $this->getMenuPayload();

        return array_merge(parent::share($request), [
            'appName' => config( 'app.name' ),
            'auth' => [
                'user' => Auth::check() ? [
                    'account_idx' => Auth::user()->account_idx,
                    'user_id'     => Auth::user()->user_id,
                    'user_level'  => Auth::user()->user_level,
                    'user_name'   => Auth::user()->user_name
                ] : null,
            ],
            // 세션 유효시간( 분 ) — 화면이 마지막 요청 + 이 시간으로 만료를 미리 안내한다 ( SessionWatcher )
            'session'     => Auth::check() ? ['lifetime' => (int) config('session.lifetime')] : null,
            'userMenu'    => $payload['menus'],
            'adminMenu'   => $payload['adminMenus'] ?? [],
            'permissions' => $payload['permissions'],
            // 화면 파일 이름표 ( DevSourceTag ) — 시스템 계정( 레벨 0 )에게만 · 시스템 설정 Common.dev_source_tag ( 11-25 )
            // 공정 순번에서 원자재출고 자리 문자 ( 시스템 설정 production.rm_out_seq_label · 11-26 — 작업지시 공정 줄 편집 중 순번 )
            'rmOutSeq'    => Auth::check() ? \MesCore\Support\OpSeq::rmLabel() : 'M',
            'devSourceTag' => Auth::check() && (int) Auth::user()->user_level === 0
                && SystemConfig::get('Common', 'dev_source_tag', 'Y') !== 'N',
        ]);
    }

    private function getMenuPayload(): array {
        if (!Auth::check()) {
            return [
                'menus'       => [],
                'adminMenus'  => [],
                'permissions' => [],
            ];
        }

        /** @var User $user */
        $user = Auth::user();
        return $this->menuPermissionService->getForUser($user);
    }
}

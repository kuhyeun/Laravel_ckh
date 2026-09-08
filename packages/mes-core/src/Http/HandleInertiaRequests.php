<?php

namespace MesCore\Http;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Menu\Services\MenuPermissionService;

class HandleInertiaRequests extends Middleware {

    public function __construct(private MenuPermissionService $menuPermissionService) {}

    public function share(Request $request): array {
        $payload = $this->getMenuPayload();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => Auth::check() ? [
                    'account_idx' => Auth::user()->account_idx,
                    'user_id'     => Auth::user()->user_id,
                    'user_level'  => Auth::user()->user_level,
                    'user_name'   => Auth::user()->user_name
                ] : null,
            ],
            'userMenu'    => $payload['menus'],
            'adminMenu'   => $payload['adminMenus'] ?? [],
            'permissions' => $payload['permissions'],
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

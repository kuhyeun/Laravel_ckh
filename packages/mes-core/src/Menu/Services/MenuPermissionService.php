<?php

namespace MesCore\Menu\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;

class MenuPermissionService {

    // 버전 기반 무효화라 TTL 은 이전 버전 캐시 키를 청소하는 안전망 역할일 뿐
    private const CACHE_TTL = 86400;

    public function getForUser(User $user): array {
        // 메뉴/옵션/권한이 바뀌면 버전이 달라져 캐시 키가 바뀜 → 자동 재빌드.
        // 변경이 없으면 동일 키 → 캐시 히트 ( 불필요한 재빌드 없음 ).
        // 레벨/템플릿 지정이 바뀌면 계산 결과가 달라지므로 키에 포함
        $key = $user->getMenuCacheKey()
            . '_l' . $user->user_level
            . '_t' . ($user->permission_template_idx ?? 0)
            . '_v' . $this->menuVersion();

        return Cache::remember($key, self::CACHE_TTL, function () use ($user) {
            return $this->buildPayload($user);
        });
    }

    // 변경 감지 서명: 행 수(삭제) + MAX(update)(수정) + MAX(create)(추가) 조합.
    // DB 직접 변경도 자동 감지된다. ( 작은 집계 쿼리 3개 = 전체 트리 재빌드보다 훨씬 저렴 )
    private function menuVersion(): string {
        $sig = function (string $table) {
            $row = DB::table($table)
                ->selectRaw('COUNT(*) c, MAX(update_datetime) u, MAX(create_datetime) cr')
                ->first();

            return $row->c . ':' . $row->u . ':' . $row->cr;
        };

        return md5(implode('|', [
            $sig('system_menu'),
            $sig('system_menu_option'),
            $sig('account_menu_permission'),
            $sig('permission_template'),
            $sig('permission_template_menu'),
            // 활성 모듈/디버그 변경도 감지 ( config 만 바꿔도 사이드바 자동 갱신 )
            json_encode(config('mes.modules', [])),
            config('app.debug') ? '1' : '0',
        ]));
    }

    public function can(User $user, string $menuCode, string $action): bool {
        $payload = $this->getForUser($user);

        // payload 의 menus 는 이미 "사용자 레벨의 menu_option 이 있는 메뉴"로 필터링됨.
        // → 맵에 없으면 그 레벨에서 접근 불가한 메뉴이므로 거부 ( 레벨 제약 강제 ).
        //   bypass( 레벨 ≤1 )도 자기 payload 에 있는 메뉴에 대해선 permissions 가 전부 true 로 채워져 있음.
        $perm = $payload['permissions'][$menuCode] ?? null;
        if ($perm === null) {
            return false;
        }

        return (bool)($perm[$action] ?? false);
    }

    public function forgetForUser(User $user): void {
        Cache::forget($user->getMenuCacheKey());
    }

    // 템플릿 권한 ( menu_idx => row ).
    // 템플릿 미지정 / 사용 중지된 템플릿은 빈 컬렉션 → 기본값 권한 없음 ( 개인 지정한 메뉴만 권한 )
    public function templatePermissions(?int $templateIdx): Collection {
        if (!$templateIdx) {
            return collect();
        }

        $isUse = DB::table('permission_template')->where('template_idx', $templateIdx)->value('is_use');
        if ($isUse !== 'Y') {
            return collect();
        }

        return DB::table('permission_template_menu')
            ->where('template_idx', $templateIdx)
            ->get(['menu_idx', 'can_read', 'can_write', 'can_delete'])
            ->keyBy('menu_idx');
    }

    // 최종 권한 = 레벨 상한(cap) AND ( 개인 override ?? 템플릿 행 )
    //   레벨 옵션은 상한일 뿐 권한을 주지 않는다 → 템플릿 행도 override 도 없으면 전부 N
    public function resolve(?object $levelOption, Collection $templateBase, ?object $override, int $menuIdx): array {
        $actions = ['can_read', 'can_write', 'can_delete'];
        $value   = $override ?? $templateBase->get($menuIdx);

        $result = [];
        foreach ($actions as $action) {
            $cap = $levelOption && $levelOption->{$action} === 'Y';
            $result[$action] = $cap && $value && $value->{$action} === 'Y';
        }

        return $result;
    }

    private function buildPayload(User $user): array {
        $userLevel = $user->user_level;

        // 기준정보관리 메뉴, 관리자 설정 메뉴, 개발 메뉴는 module 설정에 없어도 표시
        $defaultModules = ['Config', 'Basic'];
        if (config('app.debug') == true) {
            $defaultModules[] = 'Debug';
        }
        $activeModules = array_merge($defaultModules, config('mes.modules', []));

        $isBypass = ($userLevel <= 1);

        $menus = Menu::select(
                'system_menu.menu_idx',
                'system_menu.menu_name',
                'system_menu.menu_code',
                'system_menu.parent_menu_code',
                'system_menu.menu_route_name',
                'system_menu.url_path',
                'system_menu.menu_icon',
                'system_menu.menu_depth',
                'system_menu.is_admin'
            )
            ->where('system_menu.is_use', 'Y')
            ->where('system_menu.is_display', 'Y')
            ->whereIn('system_menu.module_code', $activeModules)
            ->whereHas('menuOptions', function ($query) use ($userLevel) {
                $query->where('menu_level', $userLevel);
            })
            ->with(['menuOptions' => function ($query) use ($userLevel) {
                $query->where('menu_level', $userLevel)->orderBy('menu_sort', 'asc');
            }])
            ->get();

        $permissions = [];

        if ($isBypass) {
            // Bypass 계정의 경우 모든 권한획득 ( ex. system, admin )
            foreach ($menus as $menu) {
                $permissions[$menu->menu_code] = [
                    'can_read'   => true,
                    'can_write'  => true,
                    'can_delete' => true,
                ];
            }
        } else {
            $overrides = DB::table('account_menu_permission')
                ->where('account_idx', $user->account_idx)
                ->whereIn('menu_idx', $menus->pluck('menu_idx'))
                ->get()
                ->keyBy('menu_idx');

            $templateBase = $this->templatePermissions($user->permission_template_idx);

            foreach ($menus as $menu) {
                $permissions[$menu->menu_code] = $this->resolve(
                    $menu->menuOptions->first(),
                    $templateBase,
                    $overrides->get($menu->menu_idx),
                    $menu->menu_idx
                );
            }

            // 읽기 권한 없는 메뉴는 트리에서 제외 ( 권한 맵에서도 제거 )
            $menus = $menus->filter(fn ($menu) => $permissions[$menu->menu_code]['can_read'])->values();
            $permissions = array_filter($permissions, fn ($p) => $p['can_read']);
        }

        $sorted = $menus->sortBy(function ($menu) {
            return $menu->menu_depth . '-' . ($menu->menuOptions->first()->menu_sort ?? 0);
        });

        // 관리자(시스템) 메뉴는 사이드바에서 별도 그룹으로 분리
        $businessMenus = $sorted->reject(fn ($m) => ($m->is_admin ?? 'N') === 'Y')->values();
        $systemMenus   = $sorted->filter(fn ($m) => ($m->is_admin ?? 'N') === 'Y')->values();

        return [
            'bypass'      => $isBypass,
            'menus'       => $this->buildMenuTree($businessMenus),
            'adminMenus'  => $this->buildMenuTree($systemMenus),
            'permissions' => $permissions,
        ];
    }

    // 주어진 컬렉션으로 트리 구성. 부모가 컬렉션 밖( 다른 그룹 )이면 최상위로 취급해 고아 메뉴를 살린다.
    private function buildMenuTree(Collection $menus): array {
        $codes = array_flip($menus->pluck('menu_code')->all());

        $build = function (?string $parentCode) use (&$build, $menus, $codes) {
            $branch = [];

            foreach ($menus as $menu) {
                $pc = $menu->parent_menu_code;
                $isRoot = ($parentCode === null)
                    ? ($pc === null || !isset($codes[$pc]))   // 부모 없음 or 부모가 이 그룹 밖 → 루트
                    : ($pc === $parentCode);

                if ($isRoot) {
                    $item = $menu->toArray();
                    $children = $build($menu->menu_code);
                    if ($children) {
                        $item['children'] = $children;
                    }
                    $branch[] = $item;
                }
            }

            return $branch;
        };

        return $build(null);
    }
}

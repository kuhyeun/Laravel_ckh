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
        $key = $user->getMenuCacheKey() . '_v' . $this->menuVersion();

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

        if (!$isBypass) {
            $overrides = DB::table('account_menu_permission')
                ->where('account_idx', $user->account_idx)
                ->whereIn('menu_idx', $menus->pluck('menu_idx'))
                ->get()
                ->keyBy('menu_idx');

            // 노출 여부 = can_read. 개인 override 가 있으면 우선, 없으면 레벨 옵션( menu_option )값.
            $menus = $menus->filter(function ($menu) use ($overrides) {
                $row = $overrides->get($menu->menu_idx);
                if ($row) {
                    return $row->can_read === 'Y';
                }
                $opt = $menu->menuOptions->first();
                return $opt && $opt->can_read === 'Y';
            })->values();
        } else {
            $overrides = collect();
        }

        $permissions = [];
        foreach ($menus as $menu) {
            // Bypass 계정의 경우 모든 권한획득 ( ex. system, admin )
            if ($isBypass) {
                $permissions[$menu->menu_code] = [
                    'can_read'   => true,
                    'can_write'  => true,
                    'can_delete' => true,
                ];
                continue;
            }

            $row = $overrides->get($menu->menu_idx);
            if ($row) {
                // 개인별 override 우선
                $permissions[$menu->menu_code] = [
                    'can_read'   => $row->can_read   === 'Y',
                    'can_write'  => $row->can_write  === 'Y',
                    'can_delete' => $row->can_delete === 'Y',
                ];
            } else {
                // 레벨 옵션( menu_option )의 rwx 를 기본값으로
                $opt = $menu->menuOptions->first();
                $permissions[$menu->menu_code] = [
                    'can_read'   => $opt && $opt->can_read   === 'Y',
                    'can_write'  => $opt && $opt->can_write  === 'Y',
                    'can_delete' => $opt && $opt->can_delete === 'Y',
                ];
            }
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

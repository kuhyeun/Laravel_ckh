<?php

namespace MesCore\Menu\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;

class MenuPermissionService {

    // 버전 기반 무효화라 TTL 은 이전 버전 캐시 키를 청소하는 안전망 역할일 뿐
    private const CACHE_TTL = 86400;

    // 변경 감지 서명( 아래 menuVersion )을 캐시에 둔다 ( 11-09 코드 검사 3-4 )
    //   전에는 요청마다 권한 테이블 6개를 집계했다 → 이제 저장할 때 bumpVersion() 으로 지우고, 그 사이엔 캐시 한 번 읽기
    //   DB 를 직접 고친 경우( 시더 · tinker ) 를 위해 VERSION_TTL 이 지나면 다시 집계한다 ( 늦어도 그만큼 뒤 반영 )
    private const VERSION_KEY = 'mes_menu_perm_version';
    private const VERSION_TTL = 600;

    // 요청 단위 메모 ( 한 요청에서 Inertia 공유 · MenuAuth · Gate · canPage 가 여러 번 불러도 캐시 표를 다시 읽지 않는다 )
    private static array $payloadMemo = [];
    private static array $pageCodesMemo = [];
    private static ?int $memoRequest = null;

    public function getForUser(User $user): array {
        $this->syncRequestMemo();
        $key = $this->cacheKey($user);
        return self::$payloadMemo[$key] ??= Cache::remember($key, self::CACHE_TTL, function () use ($user) {
            return $this->buildPayload($user);
        });
    }

    // 화면( page_component ) → 그 화면을 쓰는 메뉴 코드 ( MenuAuth · canPage — 전에는 부를 때마다 메뉴 표를 찾았다 )
    public function pageCodes(string $pageComponent): array {
        $this->syncRequestMemo();
        return self::$pageCodesMemo[$pageComponent] ??= Cache::remember(
            'mes_menu_page_codes_' . md5($pageComponent) . '_v' . $this->menuVersion(), self::CACHE_TTL,
            fn () => Menu::where('page_component', $pageComponent)->pluck('menu_code')->map(fn ($c) => (string) $c)->all()
        );
    }

    // 권한 · 메뉴 · 레벨 옵션 · 템플릿 · 기능 스위치를 저장한 뒤 부른다 → 다음 요청부터 새로 계산
    public function bumpVersion(): void {
        Cache::forget(self::VERSION_KEY);
        $this->resetVersionMemo();
    }

    // 요청이 바뀌면 메모를 비운다 ( 콘솔은 요청 경계가 없어 메모하지 않는다 )
    private function syncRequestMemo(): void {
        $requestId = app()->runningInConsole() ? -1 : spl_object_id(request());
        if (self::$memoRequest !== $requestId || $requestId === -1) {
            self::$payloadMemo   = [];
            self::$pageCodesMemo = [];
            self::$memoRequest   = $requestId;
        }
    }

    // 메뉴/옵션/권한이 바뀌면 버전이 달라져 캐시 키가 바뀜 → 자동 재빌드.
    // 변경이 없으면 동일 키 → 캐시 히트 ( 불필요한 재빌드 없음 ).
    // 레벨/템플릿 지정이 바뀌면 계산 결과가 달라지므로 키에 포함
    private function cacheKey(User $user): string {
        return $user->getMenuCacheKey()
            . '_l' . $user->user_level
            . '_t' . ($user->permission_template_idx ?? 0)
            . '_v' . $this->menuVersion();
    }

    // 변경 감지 대상 테이블 => 권한/메뉴 트리 계산에 쓰이는 컬럼
    //   이 컬럼 값이 하나라도 바뀌면 서명이 달라진다
    private const VERSION_COLUMNS = [
        'system_menu' => [
            'menu_idx', 'menu_code', 'parent_menu_code', 'menu_name', 'menu_depth', 'module_code',
            'menu_route_name', 'url_path', 'page_component', 'page_options', 'menu_icon', 'is_use', 'is_display', 'is_admin', 'read_only', 'feature_key',
        ],
        // 기능 스위치 값이 바뀌면 메뉴 노출이 달라진다
        'system_config'            => ['system_config_group_key', 'system_config_key', 'system_config_value', 'system_config_default_value'],
        'system_menu_option'       => ['menu_option_idx', 'menu_idx', 'menu_level', 'menu_sort', 'can_read', 'can_write', 'can_delete'],
        'account_menu_permission'  => ['permission_idx', 'account_idx', 'menu_idx', 'can_read', 'can_write', 'can_delete'],
        'permission_template'      => ['template_idx', 'is_use'],
        'permission_template_menu' => ['template_menu_idx', 'template_idx', 'menu_idx', 'can_read', 'can_write', 'can_delete'],
    ];

    // 요청 단위 메모 ( 한 요청에서 Inertia 공유·MenuAuth·Gate 가 여러 번 호출해도 서명 쿼리는 1회 )
    private static ?string $versionMemo = null;
    private static ?int $versionMemoRequest = null;

    // 변경 감지 서명: 테이블별 행 수 + 행 내용 체크섬( SUM(CRC32(행)) )
    //   update_datetime( 초 단위 )만 보면 같은 초 안의 두 번째 수정을 놓치므로 내용 자체를 해시한다.
    //   DB 직접 변경도 감지된다. ( 권한 테이블은 작아서 집계 비용 < 트리 재빌드 비용 )
    private function menuVersion(): string {
        // 콘솔( 배치·tinker )은 요청 경계가 없으므로 메모하지 않음
        $requestId = app()->runningInConsole() ? null : spl_object_id(request());
        if ($requestId !== null && self::$versionMemoRequest === $requestId) {
            return self::$versionMemo;
        }

        // 테이블 서명은 캐시( 저장 때 bumpVersion 으로 지움 · VERSION_TTL 안전망 ) — 콘솔은 늘 새로 ( 시더 · 배치가 바로 보게 )
        $tables = app()->runningInConsole()
            ? $this->tableSignature()
            : Cache::remember(self::VERSION_KEY, self::VERSION_TTL, fn () => $this->tableSignature());

        // 활성 모듈/디버그 변경도 감지 ( config 만 바꿔도 사이드바 자동 갱신 ) — 설정값이라 늘 새로
        $version = md5($tables . '|' . json_encode(config('mes.modules', [])) . '|' . (config('app.debug') ? '1' : '0'));

        if ($requestId !== null) {
            self::$versionMemo        = $version;
            self::$versionMemoRequest = $requestId;
        }

        return $version;
    }

    // 테이블별 행 수 + 행 내용 체크섬
    private function tableSignature(): string {
        $sigs = [];
        foreach (self::VERSION_COLUMNS as $table => $columns) {
            // NULL 을 구분값으로 치환 ( CONCAT_WS 는 NULL 을 건너뛰어 ( a,NULL,b ) 와 ( a,b,NULL ) 이 같아짐 )
            $rowExpr = 'CONCAT_WS(0x1F, ' . implode(', ', array_map(fn ($c) => "COALESCE(CAST(`{$c}` AS CHAR), 0x00)", $columns)) . ')';
            $row = DB::table($table)
                ->selectRaw("COUNT(*) c, COALESCE(SUM(CRC32({$rowExpr})), 0) h")
                ->first();

            $sigs[] = $table . ':' . $row->c . ':' . $row->h;
        }
        return implode('|', $sigs);
    }

    // 같은 요청 안에서 권한을 바꾼 뒤 바로 다시 계산해야 할 때 메모 초기화
    public function resetVersionMemo(): void {
        self::$versionMemo        = null;
        self::$versionMemoRequest = null;
        self::$payloadMemo        = [];
        self::$pageCodesMemo      = [];
    }

    public function can(User $user, string $menuCode, string $action): bool {
        $payload = $this->getForUser($user);

        // payload 의 menus 는 이미 "사용자 레벨의 menu_option 이 있는 메뉴"로 필터링됨.
        // → 맵에 없으면 그 레벨에서 접근 불가한 메뉴이므로 거부 ( 레벨 제약 강제 ).
        //   bypass( 레벨 0 )도 자기 payload 에 있는 메뉴에 대해선 permissions 가 전부 true 로 채워져 있음.
        $perm = $payload['permissions'][$menuCode] ?? null;
        if ($perm === null) {
            return false;
        }

        return (bool)($perm[$action] ?? false);
    }

    // 화면( page_component ) 기준 권한 — 라우트 미들웨어가 아닌 곳( 여러 화면이 같이 쓰는 API )에서 쓴다
    //   여럿을 주면 하나라도 되면 통과 ( 예: 입고검사는 입고관리 · 입고검사 어느 쪽 권한으로도 )
    public function canPage(User $user, string|array $pageComponents, string $action): bool {
        foreach ((array) $pageComponents as $page) {
            foreach ($this->pageCodes($page) as $code) {
                if ($this->can($user, $code, $action)) return true;
            }
        }
        return false;
    }

    // 지금 로그인한 사람 기준 canPage — 로그인 안 했으면 false ( 11-09 — 호출마다 Auth::user() 를 넘기던 것 )
    public function canPageForMe(string|array $pageComponents, string $action): bool {
        $user = Auth::user();
        return $user instanceof User && $this->canPage($user, $pageComponents, $action);
    }

    // 현재 버전의 캐시를 즉시 제거 ( getForUser 와 같은 키 사용 )
    //   보통은 버전 기반 자동 무효화로 충분하고, 캐시를 강제로 다시 계산해야 할 때만 사용
    public function forgetForUser(User $user): void {
        $this->bumpVersion();
        Cache::forget($this->cacheKey($user));
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
        $this->menuFlags = null;   // 새로 계산할 때마다 메뉴 표시 · 사용 값을 다시 읽는다

        // 기준정보관리 메뉴, 관리자 설정 메뉴, 개발 메뉴는 module 설정에 없어도 표시
        $defaultModules = ['Config', 'Basic'];
        if (config('app.debug') == true) {
            $defaultModules[] = 'Debug';
        }
        $activeModules = array_merge($defaultModules, config('mes.modules', []));

        // 시스템( 레벨 0 )만 전부 허용. 관리자( 레벨 1 )는 메뉴관리의 레벨 옵션 값을 그대로 권한으로 사용
        $isBypass = ($userLevel === 0);

        $menus = Menu::select(
                'system_menu.menu_idx',
                'system_menu.menu_name',
                'system_menu.menu_code',
                'system_menu.parent_menu_code',
                'system_menu.menu_route_name',
                'system_menu.url_path',
                'system_menu.menu_icon',
                'system_menu.menu_depth',
                'system_menu.is_admin',
                'system_menu.read_only'
            )
            ->where('system_menu.is_use', 'Y')
            ->where('system_menu.is_display', 'Y')
            ->whereIn('system_menu.module_code', $activeModules)
            ->featureEnabled()
            ->whereHas('menuOptions', function ($query) use ($userLevel) {
                $query->where('menu_level', $userLevel);
            })
            ->with(['menuOptions' => function ($query) use ($userLevel) {
                $query->where('menu_level', $userLevel)->orderBy('menu_sort', 'asc');
            }])
            ->get();

        $permissions = [];

        if ($isBypass) {
            // Bypass 계정의 경우 모든 권한획득 ( ex. system )
            foreach ($menus as $menu) {
                $permissions[$menu->menu_code] = [
                    'can_read'   => true,
                    'can_write'  => true,
                    'can_delete' => true,
                ];
            }
        } else {
            if ($userLevel === 1) {
                // 관리자는 템플릿 · 개인 지정 대상이 아님 → 레벨 옵션 = 권한
                foreach ($menus as $menu) {
                    $opt = $menu->menuOptions->first();
                    $permissions[$menu->menu_code] = [
                        'can_read'   => $opt->can_read   === 'Y',
                        'can_write'  => $opt->can_write  === 'Y',
                        'can_delete' => $opt->can_delete === 'Y',
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
            }

            // 읽기 권한 없는 메뉴는 트리에서 제외 ( 권한 맵에서도 제거 )
            $menus = $menus->filter(fn ($menu) => $permissions[$menu->menu_code]['can_read'])->values();
            $permissions = array_filter($permissions, fn ($p) => $p['can_read']);
        }

        // 조회 전용 메뉴 : 쓰기 · 삭제는 항상 없음 ( 시스템 계정 포함 — 이력 메뉴는 보기만 )
        foreach ($menus as $menu) {
            if (($menu->read_only ?? 'N') === 'Y' && isset($permissions[$menu->menu_code])) {
                $permissions[$menu->menu_code]['can_write']  = false;
                $permissions[$menu->menu_code]['can_delete'] = false;
            }
        }

        // 문자열 비교라 자릿수를 맞춘다 ( "2-10" 이 "2-2" 보다 앞에 오지 않도록 )
        $sorted = $menus->sortBy(function ($menu) {
            return sprintf('%03d-%05d', $menu->menu_depth, $menu->menuOptions->first()->menu_sort ?? 0);
        });

        // 조상 중 하나라도 표시 N · 사용 N 이면 사이드바에서 뺀다 ( 고아로 위로 올라오지 않게 · 권한 맵은 그대로 )
        $sorted = $sorted->reject(fn ($m) => $this->hiddenByAncestor($m->parent_menu_code))->values();

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

    // 부모를 따라 올라가며 표시 N · 사용 N 인 메뉴가 있는지 ( 없는 부모 코드는 거기서 멈춤 )
    private ?array $menuFlags = null;
    private function hiddenByAncestor(?string $parentCode): bool {
        $this->menuFlags ??= Menu::get(['menu_code', 'parent_menu_code', 'is_use', 'is_display'])->keyBy('menu_code')->all();

        for ($depth = 0; $parentCode !== null && $depth < 10; $depth++) {
            $p = $this->menuFlags[$parentCode] ?? null;
            if (!$p) return false;
            if ($p->is_use !== 'Y' || $p->is_display !== 'Y') return true;
            $parentCode = $p->parent_menu_code;
        }
        return false;
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

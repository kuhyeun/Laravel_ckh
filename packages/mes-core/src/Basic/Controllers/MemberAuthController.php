<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Models\AccountMenuPermission;
use MesCore\Menu\Models\PermissionTemplate;
use MesCore\Menu\Services\MenuPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberAuthController extends Controller {

    public function __construct(private MenuPermissionService $menuPermissionService) {}

    // 개인권한 관리 대상 사용자 목록 ( bypass(레벨 ≤1) 제외 )
    public function users() {
        $users = User::select('account_idx', 'user_id', 'user_name', 'user_level', 'permission_template_idx')
            ->where('user_level', '>', 1)
            ->orderBy('user_level')
            ->orderBy('user_id')
            ->get();

        return response()->json(['users' => $users]);
    }

    // 선택 가능한 권한 템플릿 목록
    public function templates() {
        $templates = PermissionTemplate::select('template_idx', 'template_name', 'template_desc')
            ->where('is_use', 'Y')
            ->orderBy('template_name')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    // 선택 사용자의 메뉴별 [ 레벨 cap + 기본값(템플릿) + 현재값 ]
    public function menus(int $accountIdx) {
        $user = User::find($accountIdx);
        if (!$user) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($user->user_level <= 1) {
            // bypass 사용자는 개인권한 무의미
            return response()->json(['message' => 'BypassUser'], 422);
        }

        $level = $user->user_level;

        // 해당 레벨에 옵션이 있는 메뉴만 ( = 그 레벨이 접근 대상인 메뉴 ) + 레벨 옵션(cap)
        $menus = Menu::select('menu_idx', 'menu_code', 'menu_name', 'parent_menu_code', 'menu_depth', 'url_path', 'is_admin')
            ->where('is_use', 'Y')
            ->whereHas('menuOptions', fn ($q) => $q->where('menu_level', $level))
            ->with(['menuOptions' => fn ($q) => $q->where('menu_level', $level)])
            ->orderBy('menu_depth')
            ->orderBy('menu_code')
            ->get();

        $overrides = DB::table('account_menu_permission')
            ->where('account_idx', $accountIdx)
            ->get()
            ->keyBy('menu_idx');

        $templateBase = $this->menuPermissionService->templatePermissions($user->permission_template_idx);

        $yn = fn (bool $v) => $v ? 'Y' : 'N';

        $rows = $menus->map(function ($m) use ($overrides, $templateBase, $yn) {
            $opt  = $m->menuOptions->first();
            $ov   = $overrides->get($m->menu_idx);
            $base = $this->menuPermissionService->resolve($opt, $templateBase, null, $m->menu_idx);
            $cur  = $this->menuPermissionService->resolve($opt, $templateBase, $ov, $m->menu_idx);

            return [
                'menu_idx'         => $m->menu_idx,
                'menu_code'        => $m->menu_code,
                'menu_name'        => $m->menu_name,
                'parent_menu_code' => $m->parent_menu_code,
                'menu_depth'       => $m->menu_depth,
                'url_path'         => $m->url_path,
                'is_admin'         => $m->is_admin,
                // 레벨 허용(상한) — UI 에서 이게 N 이면 토글 비활성
                'cap_read'   => $opt->can_read,
                'cap_write'  => $opt->can_write,
                'cap_delete' => $opt->can_delete,
                // 기본값 ( 템플릿 행, 없으면 N ) — cap 클램프 적용
                'base_read'   => $yn($base['can_read']),
                'base_write'  => $yn($base['can_write']),
                'base_delete' => $yn($base['can_delete']),
                // 현재값 ( override 있으면 그 값, 없으면 기본값 )
                'can_read'   => $yn($cur['can_read']),
                'can_write'  => $yn($cur['can_write']),
                'can_delete' => $yn($cur['can_delete']),
                'is_override' => $ov ? 'Y' : 'N',
            ];
        });

        return response()->json([
            'level'                   => $level,
            'permission_template_idx' => $user->permission_template_idx,
            'menus'                   => $rows,
        ]);
    }

    // 템플릿 적용 ( 템플릿 그대로 사용 → 개인 override 전부 제거 ). null 이면 템플릿 해제( 권한 없음 )
    public function applyTemplate(Request $request) {
        $validated = $request->validate([
            'account_idx'             => 'required|integer|exists:account_member,account_idx',
            'permission_template_idx' => 'nullable|integer|exists:permission_template,template_idx',
        ]);

        $user = User::find($validated['account_idx']);
        if ($user->user_level <= 1) {
            return response()->json(['message' => 'BypassUser'], 422);
        }

        try {
            DB::transaction(function () use ($user, $validated) {
                $user->permission_template_idx = $validated['permission_template_idx'] ?? null;
                $user->save();

                AccountMenuPermission::where('account_idx', $user->account_idx)->delete();
            });

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('권한 템플릿 적용 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 사용자 개인권한 저장 ( 레벨 cap 이내로 클램프. 기본값(템플릿)과 동일하면 override 제거 → 기본값 따라감 )
    public function save(Request $request) {
        $validated = $request->validate([
            'account_idx'        => 'required|integer|exists:account_member,account_idx',
            'items'              => 'required|array',
            'items.*.menu_idx'   => 'required|integer',
            'items.*.can_read'   => 'required|in:Y,N',
            'items.*.can_write'  => 'required|in:Y,N',
            'items.*.can_delete' => 'required|in:Y,N',
        ]);

        $user = User::find($validated['account_idx']);
        if (!$user || $user->user_level <= 1) {
            return response()->json(['message' => 'BypassUser'], 422);
        }
        $level = $user->user_level;

        // 레벨 cap ( menu_idx => option )
        $caps = DB::table('system_menu_option')
            ->where('menu_level', $level)
            ->get(['menu_idx', 'can_read', 'can_write', 'can_delete'])
            ->keyBy('menu_idx');

        $templateBase = $this->menuPermissionService->templatePermissions($user->permission_template_idx);

        try {
            DB::transaction(function () use ($validated, $caps, $templateBase) {
                foreach ($validated['items'] as $it) {
                    $cap = $caps->get($it['menu_idx']);
                    if (!$cap) {
                        continue;   // 레벨에 옵션 없는 메뉴는 관리 대상 아님
                    }

                    // 입력값 / 기본값 모두 cap 클램프 후 비교
                    $val  = $this->menuPermissionService->resolve($cap, $templateBase, (object) $it, $it['menu_idx']);
                    $base = $this->menuPermissionService->resolve($cap, $templateBase, null, $it['menu_idx']);

                    if ($val === $base) {
                        AccountMenuPermission::where('account_idx', $validated['account_idx'])
                            ->where('menu_idx', $it['menu_idx'])
                            ->delete();
                    } else {
                        AccountMenuPermission::updateOrCreate(
                            ['account_idx' => $validated['account_idx'], 'menu_idx' => $it['menu_idx']],
                            array_map(fn ($v) => $v ? 'Y' : 'N', $val)
                        );
                    }
                }
            });

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 권한 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Models\AccountMenuPermission;
use MesCore\Menu\Models\PermissionTemplate;
use MesCore\Menu\Models\PermissionTemplateMenu;
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
    //   preview=1 이면 아직 적용하지 않은 템플릿( preview_template_idx )으로 계산해 돌려준다.
    //   미리보기는 개인 지정을 무시한다 — 템플릿을 적용하면 개인 지정이 초기화되기 때문
    public function menus(int $accountIdx, Request $request) {
        $validated = $request->validate([
            'preview'              => 'sometimes|boolean',
            'preview_template_idx' => 'nullable|integer|exists:permission_template,template_idx',
        ]);
        $isPreview = (bool) ($validated['preview'] ?? false);

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
            ->featureEnabled()   // 꺼진 기능의 메뉴는 권한도 줄 수 없다
            ->whereHas('menuOptions', fn ($q) => $q->where('menu_level', $level))
            ->with(['menuOptions' => fn ($q) => $q->where('menu_level', $level)])
            ->get();

        // 메뉴관리에서 사용안함인 메뉴는 그 아래 메뉴까지 뺀다 ( 부모가 빠져 최상위로 올라오지 않게 )
        $useFlags = Menu::get(['menu_code', 'parent_menu_code', 'is_use'])->keyBy('menu_code');
        $unusedAncestor = function (?string $code) use ($useFlags): bool {
            for ($i = 0; $code !== null && $i < 10; $i++) {
                $p = $useFlags->get($code);
                if (!$p) return false;
                if ($p->is_use !== 'Y') return true;
                $code = $p->parent_menu_code;
            }
            return false;
        };

        // 정렬 = 사이드바와 같은 순서 ( 사용자 메뉴 → 관리자 메뉴 · 같은 부모 안에서는 그 레벨의 menu_sort )
        //   화면( orderTree )이 부모 → 자식으로 묶을 때 형제 순서는 이 순서를 그대로 따른다
        $menus = $menus->reject(fn ($m) => $unusedAncestor($m->parent_menu_code))
            ->sortBy(fn ($m) => sprintf('%s-%05d-%s',
                $m->is_admin === 'Y' ? '1' : '0',
                $m->menuOptions->first()->menu_sort ?? 0,
                $m->menu_code))
            ->values();

        // 미리보기는 개인 지정을 반영하지 않음 ( 적용 시 초기화되는 값 )
        $overrides = $isPreview
            ? collect()
            : DB::table('account_menu_permission')->where('account_idx', $accountIdx)->get()->keyBy('menu_idx');

        $templateIdx  = $isPreview ? ($validated['preview_template_idx'] ?? null) : $user->permission_template_idx;
        $templateBase = $this->menuPermissionService->templatePermissions($templateIdx);

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
            'preview'                 => $isPreview ? 'Y' : 'N',
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

            $this->menuPermissionService->bumpVersion();   // 권한 캐시 서명 지움 → 다음 요청부터 새 권한
            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('권한 템플릿 적용 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 화면에 보이는 권한을 템플릿으로 저장 ( 사용자에게 적용하지는 않는다 — 적용은 [템플릿 적용] )
    //   items = 화면 값 그대로 · 켜진 것이 하나라도 있는 메뉴만 행으로 남긴다 ( 행 없음 = 권한 없음 )
    //   같은 이름이 있으면 overwrite=1 일 때만 덮어쓴다 → 그 템플릿을 쓰는 사용자 전원에게 바로 반영
    //   덮어쓸 때도 바뀌는 건 화면에 있던 메뉴뿐 ( 다른 레벨에만 있는 메뉴의 템플릿 값은 남는다 )
    public function saveTemplate(Request $request) {
        $validated = $request->validate([
            'template_name'      => 'required|string|max:50',
            'overwrite'          => 'sometimes|boolean',
            'items'              => 'required|array',
            'items.*.menu_idx'   => 'required|integer|exists:system_menu,menu_idx',
            'items.*.can_read'   => 'required|in:Y,N',
            'items.*.can_write'  => 'required|in:Y,N',
            'items.*.can_delete' => 'required|in:Y,N',
        ]);
        $name = trim($validated['template_name']);
        if ($name === '') {
            return response()->json(['message' => 'NameRequired'], 422);
        }

        $exists = PermissionTemplate::where('template_name', $name)->first();
        if ($exists && !($validated['overwrite'] ?? false)) {
            // 화면에서 덮어쓸지 묻는다 ( 연결된 사용자 수와 함께 )
            return response()->json([
                'message'    => 'Duplicate',
                'user_count' => User::where('permission_template_idx', $exists->template_idx)->count(),
            ], 409);
        }

        try {
            $template = DB::transaction(function () use ($exists, $name, $validated) {
                $template = $exists ?? PermissionTemplate::create(['template_name' => $name, 'is_use' => 'Y']);

                // 화면에 있던 메뉴의 행만 바꾼다 ( 이 레벨에 안 보이는 메뉴의 기존 행은 그대로 — 다른 레벨 사용자가 같은 템플릿을 쓸 수 있다 )
                PermissionTemplateMenu::where('template_idx', $template->template_idx)
                    ->whereIn('menu_idx', array_column($validated['items'], 'menu_idx'))
                    ->delete();
                foreach ($validated['items'] as $it) {
                    if ($it['can_read'] !== 'Y' && $it['can_write'] !== 'Y' && $it['can_delete'] !== 'Y') {
                        continue;
                    }
                    PermissionTemplateMenu::create([
                        'template_idx' => $template->template_idx,
                        'menu_idx'     => $it['menu_idx'],
                        'can_read'     => $it['can_read'],
                        'can_write'    => $it['can_write'],
                        'can_delete'   => $it['can_delete'],
                    ]);
                }
                if ($exists) {
                    $template->touch();   // 수정자 · 수정일
                }
                return $template;
            });

            $this->menuPermissionService->bumpVersion();   // 덮어쓴 경우 연결된 사용자 권한이 바뀐다
            return response()->json(['message' => 'Success', 'template_idx' => $template->template_idx], 200);
        } catch (\Throwable $e) {
            Log::error('권한 템플릿 저장 실패 : ' . $e->getMessage());
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

            $this->menuPermissionService->bumpVersion();   // 권한 캐시 서명 지움 → 다음 요청부터 새 권한
            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 권한 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

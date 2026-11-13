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
    //   템플릿 이름 · 개인 지정( 예외 ) 수 · 부서 이름도 함께 ( 11-13 — 목록에서 누가 어떤 템플릿인지 · 예외가 있는지 )
    public function users() {
        $users = User::select('account_idx', 'user_id', 'user_name', 'user_level', 'permission_template_idx', 'is_use')
            ->addSelect([
                'template_name'  => DB::table('permission_template')->select('template_name')->whereColumn('template_idx', 'account_member.permission_template_idx')->limit(1),
                'override_count' => DB::table('account_menu_permission')->selectRaw('count(*)')->whereColumn('account_idx', 'account_member.account_idx'),
                'dept_name'      => DB::table('system_code')->select('system_code_name')->where('system_code_group_key', 'DEPT')->whereColumn('system_code_key', 'account_member.dept_code')->limit(1),
            ])
            ->where('user_level', '>', 1)
            ->orderBy('user_level')
            ->orderBy('user_id')
            ->get();

        return response()->json(['users' => $users]);
    }

    // 선택 가능한 권한 템플릿 목록 ( + 연결된 사용자 수 )
    public function templates() {
        $templates = PermissionTemplate::select('template_idx', 'template_name', 'template_desc')
            ->addSelect(['user_count' => DB::table('account_member')->selectRaw('count(*)')->whereColumn('permission_template_idx', 'permission_template.template_idx')])
            ->where('is_use', 'Y')
            ->orderBy('template_name')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    // ---- 템플릿 관리 ( 11-13 — 템플릿을 직접 고치고 · 사람을 한 번에 붙인다 ) ----

    // 템플릿의 메뉴별 권한 ( template_idx 없으면 빈 값 — 새 템플릿 )
    //   대상 메뉴 = 템플릿을 쓰는 레벨( 10 · 99 ) 어디든 옵션이 있는 메뉴 · 상한( cap ) = 그 레벨 중 하나라도 허용
    //   ( 실제 권한은 사용자 레벨의 상한으로 한 번 더 걸러진다 — resolve )
    public function templateMenus(Request $request) {
        $v = $request->validate(['template_idx' => 'nullable|integer|exists:permission_template,template_idx']);
        $levels = [10, 99];

        $menus = Menu::select('menu_idx', 'menu_code', 'menu_name', 'parent_menu_code', 'menu_depth', 'url_path', 'is_admin')
            ->where('is_use', 'Y')
            ->featureEnabled()
            ->whereHas('menuOptions', fn ($q) => $q->whereIn('menu_level', $levels))
            ->with(['menuOptions' => fn ($q) => $q->whereIn('menu_level', $levels)->orderByDesc('menu_level')])
            ->get();

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
        $menus = $menus->reject(fn ($m) => $unusedAncestor($m->parent_menu_code))
            ->sortBy(fn ($m) => sprintf('%s-%05d-%s', $m->is_admin === 'Y' ? '1' : '0', $m->menuOptions->first()->menu_sort ?? 0, $m->menu_code))
            ->values();

        $base = $this->menuPermissionService->templatePermissions($v['template_idx'] ?? null);
        $rows = $menus->map(function ($m) use ($base) {
            $cap = fn (string $a) => $m->menuOptions->contains(fn ($o) => $o->{$a} === 'Y') ? 'Y' : 'N';
            $t   = $base->get($m->menu_idx);
            $val = fn (string $a) => ($t && $t->{$a} === 'Y' && $cap($a) === 'Y') ? 'Y' : 'N';
            return [
                'menu_idx' => $m->menu_idx, 'menu_code' => $m->menu_code, 'menu_name' => $m->menu_name,
                'parent_menu_code' => $m->parent_menu_code, 'menu_depth' => $m->menu_depth, 'url_path' => $m->url_path, 'is_admin' => $m->is_admin,
                'cap_read' => $cap('can_read'), 'cap_write' => $cap('can_write'), 'cap_delete' => $cap('can_delete'),
                'can_read' => $val('can_read'), 'can_write' => $val('can_write'), 'can_delete' => $val('can_delete'),
                'is_override' => 'N',
            ];
        });

        return response()->json(['menus' => $rows]);
    }

    // 템플릿 저장 ( template_idx 있으면 수정 — 연결된 사용자 전원에게 바로 반영 · 없으면 새로 )
    public function templateSave(Request $request) {
        $idx = $request->input('template_idx');
        $v = $request->validate([
            'template_idx'       => 'nullable|integer|exists:permission_template,template_idx',
            'template_name'      => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::unique('permission_template', 'template_name')->ignore($idx, 'template_idx')],
            'template_desc'      => 'nullable|string|max:200',
            'items'              => 'required|array',
            'items.*.menu_idx'   => 'required|integer|exists:system_menu,menu_idx',
            'items.*.can_read'   => 'required|in:Y,N',
            'items.*.can_write'  => 'required|in:Y,N',
            'items.*.can_delete' => 'required|in:Y,N',
        ], ['template_name.unique' => '같은 이름의 템플릿이 있습니다.'], ['template_name' => '템플릿 이름']);

        $template = DB::transaction(function () use ($v) {
            $template = !empty($v['template_idx']) ? PermissionTemplate::findOrFail($v['template_idx']) : new PermissionTemplate(['is_use' => 'Y']);
            $template->template_name = trim($v['template_name']);
            $template->template_desc = $v['template_desc'] ?? null;
            $template->save();
            $this->replaceTemplateMenus($template->template_idx, $v['items']);
            $template->touch();
            return $template;
        });
        $this->pruneOverridesOf(User::where('permission_template_idx', $template->template_idx)->pluck('account_idx')->all());
        $this->menuPermissionService->bumpVersion();

        return response()->json(['message' => 'Success', 'template_idx' => $template->template_idx]);
    }

    // 템플릿 복사 ( 메뉴 권한 그대로 · 사용자는 붙지 않는다 )
    public function templateCopy(Request $request) {
        $v = $request->validate([
            'template_idx'  => 'required|integer|exists:permission_template,template_idx',
            'template_name' => 'required|string|max:50|unique:permission_template,template_name',
        ], ['template_name.unique' => '같은 이름의 템플릿이 있습니다.'], ['template_name' => '템플릿 이름']);

        $copy = DB::transaction(function () use ($v) {
            $src  = PermissionTemplate::findOrFail($v['template_idx']);
            $copy = PermissionTemplate::create(['template_name' => trim($v['template_name']), 'template_desc' => $src->template_desc, 'is_use' => 'Y']);
            foreach (PermissionTemplateMenu::where('template_idx', $src->template_idx)->get() as $m) {
                PermissionTemplateMenu::create(['template_idx' => $copy->template_idx, 'menu_idx' => $m->menu_idx,
                    'can_read' => $m->can_read, 'can_write' => $m->can_write, 'can_delete' => $m->can_delete]);
            }
            return $copy;
        });

        return response()->json(['message' => 'Success', 'template_idx' => $copy->template_idx]);
    }

    // 템플릿 삭제 — 쓰는 사용자가 있으면 막는다 ( 먼저 다른 템플릿으로 옮긴다 )
    public function templateDelete(Request $request) {
        $v = $request->validate(['template_idx' => 'required|integer|exists:permission_template,template_idx']);
        $cnt = User::where('permission_template_idx', $v['template_idx'])->count();
        if ($cnt > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['template_idx' => ["이 템플릿을 쓰는 사용자가 {$cnt}명 있습니다. 먼저 다른 템플릿으로 옮기세요."]]);
        }
        PermissionTemplate::whereKey($v['template_idx'])->delete();   // 메뉴 행은 FK cascade
        $this->menuPermissionService->bumpVersion();

        return response()->json(['message' => 'Success']);
    }

    // 템플릿 일괄 지정 ( 여러 사용자 → 템플릿 하나 · null = 템플릿 없음 )
    //   keep_overrides 가 아니면 개인 지정( 예외 )을 지운다 — 예외는 이전 템플릿 기준으로 만든 것
    //   유지할 때도 새 템플릿 값과 같아진 예외는 지운다 ( 예외로 남을 이유가 없다 )
    public function assignTemplate(Request $request) {
        $v = $request->validate([
            'account_idxs'            => 'required|array|min:1|max:500',
            'account_idxs.*'          => 'integer',
            'permission_template_idx' => 'nullable|integer|exists:permission_template,template_idx',
            'keep_overrides'          => 'sometimes|boolean',
        ]);
        $users = User::whereIn('account_idx', array_unique($v['account_idxs']))->where('user_level', '>', 1)->get();
        if ($users->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $keep = (bool) ($v['keep_overrides'] ?? false);
        $ids  = $users->pluck('account_idx')->all();

        DB::transaction(function () use ($ids, $v, $keep) {
            User::whereIn('account_idx', $ids)->update(['permission_template_idx' => $v['permission_template_idx'] ?? null, 'update_account_idx' => \Illuminate\Support\Facades\Auth::id()]);
            if (!$keep) {
                AccountMenuPermission::whereIn('account_idx', $ids)->delete();
            }
        });
        if ($keep) $this->pruneOverridesOf($ids);
        $this->menuPermissionService->bumpVersion();

        return response()->json(['message' => 'Success', 'count' => count($ids)]);
    }

    // 개인 지정( 예외 ) 초기화 — 템플릿 값 그대로
    public function resetOverrides(Request $request) {
        $v = $request->validate(['account_idxs' => 'required|array|min:1|max:500', 'account_idxs.*' => 'integer']);
        $deleted = AccountMenuPermission::whereIn('account_idx', array_unique($v['account_idxs']))->delete();
        $this->menuPermissionService->bumpVersion();

        return response()->json(['message' => 'Success', 'deleted' => $deleted]);
    }

    // 화면에 있던 메뉴의 템플릿 행만 바꾼다 ( 켜진 것이 하나라도 있는 메뉴만 행으로 )
    private function replaceTemplateMenus(int $templateIdx, array $items): void {
        PermissionTemplateMenu::where('template_idx', $templateIdx)->whereIn('menu_idx', array_column($items, 'menu_idx'))->delete();
        foreach ($items as $it) {
            if ($it['can_read'] !== 'Y' && $it['can_write'] !== 'Y' && $it['can_delete'] !== 'Y') {
                continue;
            }
            PermissionTemplateMenu::create([
                'template_idx' => $templateIdx, 'menu_idx' => $it['menu_idx'],
                'can_read' => $it['can_read'], 'can_write' => $it['can_write'], 'can_delete' => $it['can_delete'],
            ]);
        }
    }

    // 템플릿 값과 같아진 개인 지정은 지운다 ( 그 사용자 레벨의 상한으로 거른 값끼리 비교 )
    private function pruneOverridesOf(array $accountIdxs): void {
        if (!$accountIdxs) return;
        $users = User::whereIn('account_idx', $accountIdxs)->get(['account_idx', 'user_level', 'permission_template_idx'])->keyBy('account_idx');
        $rows  = AccountMenuPermission::whereIn('account_idx', $accountIdxs)->get();
        if ($rows->isEmpty()) return;
        $caps = DB::table('system_menu_option')->whereIn('menu_level', $users->pluck('user_level')->unique())
            ->get(['menu_idx', 'menu_level', 'can_read', 'can_write', 'can_delete'])->groupBy('menu_level')->map->keyBy('menu_idx');
        $bases = [];
        foreach ($rows as $ov) {
            $u    = $users->get($ov->account_idx);
            $cap  = $caps->get($u->user_level)?->get($ov->menu_idx);
            $base = $bases[$u->permission_template_idx ?? 0] ??= $this->menuPermissionService->templatePermissions($u->permission_template_idx);
            if (!$cap || $this->menuPermissionService->resolve($cap, $base, $ov, $ov->menu_idx) === $this->menuPermissionService->resolve($cap, $base, null, $ov->menu_idx)) {
                $ov->delete();
            }
        }
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

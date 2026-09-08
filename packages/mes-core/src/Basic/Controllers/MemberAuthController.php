<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Models\AccountMenuPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberAuthController extends Controller {

    // 개인권한 관리 대상 사용자 목록 ( bypass(레벨 ≤1) 제외 )
    public function users() {
        $users = User::select('account_idx', 'user_id', 'user_name', 'user_level')
            ->where('user_level', '>', 1)
            ->orderBy('user_level')
            ->orderBy('user_id')
            ->get();

        return response()->json(['users' => $users]);
    }

    // 선택 사용자의 메뉴별 [ 레벨 cap + 현재값(override 클램프) ]
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

        $clamp = fn ($cap, $ov) => ($cap === 'Y' && $ov === 'Y') ? 'Y' : 'N';

        $rows = $menus->map(function ($m) use ($overrides, $clamp) {
            $opt = $m->menuOptions->first();
            $cap = [
                'can_read'   => $opt->can_read,
                'can_write'  => $opt->can_write,
                'can_delete' => $opt->can_delete,
            ];
            $ov  = $overrides->get($m->menu_idx);
            $cur = $ov
                ? [
                    'can_read'   => $clamp($cap['can_read'],   $ov->can_read),
                    'can_write'  => $clamp($cap['can_write'],  $ov->can_write),
                    'can_delete' => $clamp($cap['can_delete'], $ov->can_delete),
                ]
                : $cap;

            return [
                'menu_idx'         => $m->menu_idx,
                'menu_code'        => $m->menu_code,
                'menu_name'        => $m->menu_name,
                'parent_menu_code' => $m->parent_menu_code,
                'menu_depth'       => $m->menu_depth,
                'url_path'         => $m->url_path,
                'is_admin'         => $m->is_admin,
                // 레벨 허용(상한) — UI 에서 이게 N 이면 토글 비활성
                'cap_read'   => $cap['can_read'],
                'cap_write'  => $cap['can_write'],
                'cap_delete' => $cap['can_delete'],
                // 현재값 ( override 있으면 그 값(클램프), 없으면 레벨값 )
                'can_read'   => $cur['can_read'],
                'can_write'  => $cur['can_write'],
                'can_delete' => $cur['can_delete'],
            ];
        });

        return response()->json(['level' => $level, 'menus' => $rows]);
    }

    // 사용자 개인권한 저장 ( 레벨 cap 이내로 클램프. 레벨과 동일하면 override 제거 → 레벨 따라감 )
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

        $clamp = fn ($cap, $v) => ($cap === 'Y' && $v === 'Y') ? 'Y' : 'N';

        try {
            DB::transaction(function () use ($validated, $caps, $clamp) {
                foreach ($validated['items'] as $it) {
                    $cap = $caps->get($it['menu_idx']);
                    if (!$cap) {
                        continue;   // 레벨에 옵션 없는 메뉴는 관리 대상 아님
                    }

                    $val = [
                        'can_read'   => $clamp($cap->can_read,   $it['can_read']),
                        'can_write'  => $clamp($cap->can_write,  $it['can_write']),
                        'can_delete' => $clamp($cap->can_delete, $it['can_delete']),
                    ];

                    // 레벨값과 동일하면 override 제거( 레벨 따라감 ), 다르면 upsert
                    $sameAsCap = $val['can_read'] === $cap->can_read
                        && $val['can_write'] === $cap->can_write
                        && $val['can_delete'] === $cap->can_delete;

                    if ($sameAsCap) {
                        AccountMenuPermission::where('account_idx', $validated['account_idx'])
                            ->where('menu_idx', $it['menu_idx'])
                            ->delete();
                    } else {
                        AccountMenuPermission::updateOrCreate(
                            ['account_idx' => $validated['account_idx'], 'menu_idx' => $it['menu_idx']],
                            $val
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

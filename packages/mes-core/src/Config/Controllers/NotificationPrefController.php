<?php

namespace MesCore\Config\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use MesCore\Auth\Models\User;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Support\NotificationService;

/**
 * 알림관리 ( Config/NotificationManage · 11-23 ) — 사용자 × 알림 종류마다 받을지 정한다
 *   표 notification_pref : 행이 없으면 받음 · 보낼 때 NotificationService::send 가 끈 사람을 뺀다
 *   eligible : 그 종류를 받을 수 있는 권한이 있는지 ( KIND_INFO needs — 없으면 켜 둬도 안 온다 · 화면에 '권한 없음' )
 *   내 알림 목록은 공통 API( common.notifications.* ) — 상단 종 [전체 보기]
 */
class NotificationPrefController extends Controller {

    public function __construct(private MenuPermissionService $perms) {}

    public function list() {
        $kinds = collect(NotificationService::KINDS)->map(fn ($label, $kind) => [
            'value' => $kind,
            'label' => $label,
            'when'  => NotificationService::KIND_INFO[$kind]['when'] ?? '',
            'to'    => NotificationService::KIND_INFO[$kind]['to'] ?? '',
            'needs' => NotificationService::KIND_INFO[$kind]['needs'][2] ?? null,
        ])->values();

        $off   = DB::table('notification_pref')->where('is_on', 'N')->get(['account_idx', 'kind'])->groupBy('account_idx');
        // 사용 중인 사용자 ( 시스템 계정 제외 — 사람 고르기 목록과 같다 )
        $users = User::pickable()->orderBy('user_name')->get()->map(function (User $u) use ($off) {
            $eligible = [];
            foreach (NotificationService::KINDS as $kind => $_) {
                $need = NotificationService::KIND_INFO[$kind]['needs'] ?? null;
                $eligible[$kind] = (!$need || $this->perms->canPage($u, $need[0], $need[1]));
            }
            return [
                'account_idx' => (int) $u->account_idx,
                'user_id'     => $u->user_id,
                'user_name'   => $u->user_name,
                'off'         => ($off[$u->account_idx] ?? collect())->pluck('kind')->values(),
                'eligible'    => $eligible,
            ];
        })->values();

        return response()->json(['kinds' => $kinds, 'users' => $users]);
    }

    // 바뀐 칸만 [{ account_idx, kind, on }] — 켜면 행을 지운다( 기본 = 받음 ) · 끄면 is_on = N
    public function save(Request $request) {
        $v = $request->validate([
            'changes'               => 'required|array|min:1|max:5000',
            'changes.*.account_idx' => ['required', 'integer', User::pickableRule()],
            'changes.*.kind'        => ['required', Rule::in(array_keys(NotificationService::KINDS))],
            'changes.*.on'          => 'required|boolean',
        ]);
        DB::transaction(function () use ($v) {
            $now = now();
            foreach ($v['changes'] as $c) {
                $key = ['account_idx' => (int) $c['account_idx'], 'kind' => $c['kind']];
                if ($c['on']) {
                    DB::table('notification_pref')->where($key)->delete();
                } else {
                    DB::table('notification_pref')->updateOrInsert($key, ['is_on' => 'N', 'update_account_idx' => Auth::id(), 'updated_at' => $now]);
                }
            }
        });
        return response()->json(['message' => 'Success', 'saved' => count($v['changes'])]);
    }
}

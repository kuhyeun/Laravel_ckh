<?php

namespace MesCore\Basic\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Controller;

/**
 * 활동 로그 ( 설정 > 활동 로그 · 11-12 ) — 조회 전용 · 표 activity_log ( ActivityLogger 미들웨어가 쌓는다 )
 *   목록 : 기간 · 사용자 · 활동 종류 · 성공 / 실패 · 검색어( 아이디 · 이름 · 메뉴 · 활동 · 대상 · 주소 ) — 서버 페이징 · 최신 순
 *   상세 : 한 줄의 보낸 값( payload ) · 브라우저
 */
class ActivityLogController extends Controller {
    use RespondsWithList, SearchesKeyword;

    private const SORTABLE = ['logged_at', 'user_id', 'user_name', 'menu_path', 'action_name', 'target', 'is_success', 'ip'];

    public const ACTIONS = [
        'VIEW' => '화면 열기', 'EXPORT' => '엑셀 내보내기', 'SAVE' => '저장', 'DELETE' => '삭제', 'CANCEL' => '취소',
        'ACTION' => '그 밖의 처리', 'LOGIN' => '로그인', 'LOGIN_FAIL' => '로그인 실패', 'LOGOUT' => '로그아웃',
    ];

    protected function listTieBreaker(): ?string {
        return 'activity_log_idx';
    }

    public function formData() {
        return response()->json([
            'actions' => collect(self::ACTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'users'   => DB::table('account_member')->orderBy('user_id')->get(['account_idx', 'user_id', 'user_name'])
                ->map(fn ($u) => ['value' => $u->account_idx, 'label' => trim("{$u->user_name} ( {$u->user_id} )")])->values(),
        ]);
    }

    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'account_idx' => 'nullable|integer', 'action' => 'nullable|string|max:30', 'is_success' => 'nullable|in:Y,N',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $q = DB::table('activity_log')->select(['activity_log_idx', 'logged_at', 'account_idx', 'user_id', 'user_name', 'menu_code', 'menu_path',
            'action', 'action_name', 'route_name', 'method', 'url', 'target', 'is_success', 'status_code', 'message', 'ip'])
            ->orderByDesc('logged_at')->orderByDesc('activity_log_idx');
        if (!empty($v['from'])) $q->where('logged_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))   $q->where('logged_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['account_idx'])) $q->where('account_idx', $v['account_idx']);
        if (!empty($v['action']))      $q->where('action', $v['action']);
        if (!empty($v['is_success']))  $q->where('is_success', $v['is_success']);
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'user'   => fn ($q, $kw) => $q->where(fn ($w) => $w->where('user_id', 'like', $kw)->orWhere('user_name', 'like', $kw)),
            'menu'   => fn ($q, $kw) => $q->where('menu_path', 'like', $kw),
            'action' => fn ($q, $kw) => $q->where('action_name', 'like', $kw),
            'target' => fn ($q, $kw) => $q->where('target', 'like', $kw),
            'url'    => fn ($q, $kw) => $q->where(fn ($w) => $w->where('url', 'like', $kw)->orWhere('route_name', 'like', $kw)),
        ]);

        return $this->listResponse($request, $q, self::SORTABLE, 'rows', fn ($r) => [
            'activity_log_idx' => (int) $r->activity_log_idx,
            'logged_at'   => substr((string) $r->logged_at, 0, 19),
            'user_id'     => $r->user_id,
            'user_name'   => $r->user_name,
            'menu_path'   => $r->menu_path,
            'action'      => $r->action,
            'action_name' => $r->action_name,
            'target'      => $r->target,
            'is_success'  => $r->is_success,
            'status_code' => (int) $r->status_code,
            'message'     => $r->message,
            'method'      => $r->method,
            'url'         => $r->url,
            'route_name'  => $r->route_name,
            'ip'          => $r->ip,
        ]);
    }

    public function show(int $idx) {
        $r = DB::table('activity_log')->where('activity_log_idx', $idx)->first();
        if (!$r) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $payload = $r->payload !== null ? json_decode($r->payload, true) : null;
        return response()->json(['log' => [
            'logged_at' => substr((string) $r->logged_at, 0, 19), 'user' => trim("{$r->user_name} ( {$r->user_id} )"), 'menu_path' => $r->menu_path,
            'action_name' => $r->action_name, 'target' => $r->target, 'is_success' => $r->is_success, 'status_code' => (int) $r->status_code,
            'message' => $r->message, 'method' => $r->method, 'url' => $r->url, 'route_name' => $r->route_name, 'ip' => $r->ip, 'user_agent' => $r->user_agent,
            // 잘린 JSON 은 글자 그대로
            'payload' => $payload ?? $r->payload,
        ]]);
    }
}

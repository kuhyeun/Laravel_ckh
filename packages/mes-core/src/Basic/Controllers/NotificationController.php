<?php

namespace MesCore\Basic\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Support\NotificationService;

/**
 * 내 알림 ( 공통 — 로그인만 · 11-13 ) — 로그인한 사용자 자신의 알림만 읽고 읽음 표시한다
 *   상단 종 : summary( 안 읽은 수 + 최근 10건 ) · 알림관리 화면 : formData · list
 *   주소 : 알림의 page_component → 메뉴 url_path ( 그 화면을 읽을 수 없으면 주소 없음 ) + ?kw=검색어
 */
class NotificationController extends Controller {
    use RespondsWithList, SearchesKeyword;

    private const SORTABLE = ['created_at', 'kind', 'title', 'read_at', 'actor_name'];

    public function __construct(private MenuPermissionService $perms) {}

    protected function listTieBreaker(): ?string {
        return 'notification_idx';
    }

    public function summary() {
        $rows = $this->base()->orderByDesc('n.created_at')->orderByDesc('n.notification_idx')->limit(10)->get();
        return response()->json([
            'unread' => $this->unreadCount(),
            'rows'   => $this->mapRows($rows),
        ]);
    }

    public function formData() {
        return response()->json([
            'kinds' => collect(NotificationService::KINDS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'kind' => 'nullable|string|max:30', 'read' => 'nullable|in:Y,N',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $q = $this->base()->orderByDesc('n.created_at')->orderByDesc('n.notification_idx');
        if (!empty($v['from'])) $q->where('n.created_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))   $q->where('n.created_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['kind'])) $q->where('n.kind', $v['kind']);
        if (($v['read'] ?? null) === 'Y') $q->whereNotNull('n.read_at');
        if (($v['read'] ?? null) === 'N') $q->whereNull('n.read_at');
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'title' => fn ($q, $kw) => $q->where(fn ($w) => $w->where('n.title', 'like', $kw)->orWhere('n.body', 'like', $kw)),
            'no'    => fn ($q, $kw) => $q->where('n.keyword', 'like', $kw),
            'actor' => fn ($q, $kw) => $q->whereIn('n.actor_idx', DB::table('account_member')->where('user_name', 'like', $kw)->select('account_idx')),
        ]);

        $urls = $this->urlMap();
        $res  = $this->listResponse($request, $q, self::SORTABLE, 'rows', fn ($r) => $this->mapRow($r, $urls));
        $data = $res->getData(true);
        $data['unread'] = $this->unreadCount();
        return response()->json($data);
    }

    // 읽음 표시 ( 누를 때 ) — 내 알림만
    public function read(int $idx) {
        $this->mine()->where('notification_idx', $idx)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Success', 'unread' => $this->unreadCount()]);
    }

    // 모두 읽음 ( idxs 가 있으면 그것만 )
    public function readAll(Request $request) {
        $v = $request->validate(['idxs' => 'nullable|array|max:1000', 'idxs.*' => 'integer']);
        $q = $this->mine()->whereNull('read_at');
        if (!empty($v['idxs'])) $q->whereIn('notification_idx', $v['idxs']);
        $q->update(['read_at' => now()]);
        return response()->json(['message' => 'Success', 'unread' => $this->unreadCount()]);
    }

    private function mine() {
        return DB::table('notification')->where('account_idx', Auth::id());
    }

    private function unreadCount(): int {
        return $this->mine()->whereNull('read_at')->count();
    }

    private function base() {
        // 보낸 사람 이름은 서브쿼리 ( 조인하면 정렬 열 created_at 이 겹친다 )
        return DB::table('notification as n')
            ->where('n.account_idx', Auth::id())
            ->select(['n.notification_idx', 'n.kind', 'n.title', 'n.body', 'n.page_component', 'n.keyword', 'n.ref_type', 'n.ref_idx',
                'n.created_at', 'n.read_at'])
            ->selectSub(DB::table('account_member as m')->whereColumn('m.account_idx', 'n.actor_idx')->select('m.user_name')->limit(1), 'actor_name');
    }

    // page_component → url_path ( 내가 읽을 수 있는 화면만 )
    private function urlMap(): array {
        $user = Auth::user();
        return DB::table('system_menu')->where('is_use', 'Y')->whereNotNull('page_component')->whereNotNull('url_path')
            ->pluck('url_path', 'page_component')
            ->filter(fn ($url, $page) => $user && $this->perms->canPage($user, $page, 'can_read'))
            ->all();
    }

    private function mapRows($rows): array {
        $urls = $this->urlMap();
        return $rows->map(fn ($r) => $this->mapRow($r, $urls))->values()->all();
    }

    private function mapRow(object $r, array $urls): array {
        $url = $r->page_component ? ($urls[$r->page_component] ?? null) : null;
        if ($url && $r->keyword) $url .= '?kw=' . rawurlencode($r->keyword);
        return [
            'notification_idx' => (int) $r->notification_idx,
            'kind'       => $r->kind,
            'kind_name'  => NotificationService::KINDS[$r->kind] ?? $r->kind,
            'title'      => $r->title,
            'body'       => $r->body,
            'keyword'    => $r->keyword,
            'url'        => $url,
            'actor_name' => $r->actor_name,
            'created_at' => substr((string) $r->created_at, 0, 19),
            'read_at'    => $r->read_at ? substr((string) $r->read_at, 0, 19) : null,
        ];
    }
}

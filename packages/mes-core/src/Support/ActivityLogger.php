<?php

namespace MesCore\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 활동 로그 미들웨어 ( web 묶음 — CoreServiceProvider · 11-12 ) — 표 activity_log
 *   남기는 것 : GET 이 아닌 요청 전부( 저장 · 삭제 · 취소 · 승인 … ) · 화면 열기( 제네릭 페이지 ) · 엑셀 내보내기( export=1 ) · 로그인 · 로그인 실패 · 로그아웃
 *   남기지 않는 것 : 목록 · 상세 조회( GET ) · 세션 유지( keepAlive ) · 로그인 안 한 요청( 로그인 제외 )
 *   메뉴 : ① 권한 검사( MenuAuth )가 찾은 메뉴 ② 화면 주소( url_path ) ③ 요청을 보낸 화면( Referer 의 주소 )
 *   대상 : 응답 · 요청의 문서 번호( *_no ) → 없으면 IDX( *_idx )
 *   보낸 값 : 비밀번호 · 토큰은 가리고 · 파일은 이름만 · 20000자에서 자름
 *   기록이 실패해도 요청은 그대로 ( 경고 로그만 )
 */
class ActivityLogger {

    public const REQ_MENU = 'activity.menu_codes';   // MenuAuth 가 넣는 메뉴 코드

    private const SKIP_ROUTES = ['keepAlive'];
    private const MASK_KEYS   = ['password', 'password_confirmation', 'new_password', 'current_password', '_token', 'token', 'secret'];
    private const PAYLOAD_MAX = 20000;

    // 요청 이름 끝 → [ 활동 종류, 화면에 보이는 이름 ]
    private const ACTIONS = [
        'save' => ['SAVE', '저장'], 'saveMany' => ['SAVE', '저장'], 'saveTree' => ['SAVE', 'BOM 트리 저장'], 'saveLineSetup' => ['SAVE', '줄 설정 저장'],
        'del' => ['DELETE', '삭제'], 'delete' => ['DELETE', '삭제'], 'many' => ['DELETE', '여러 건 삭제'],
        'cancel' => ['CANCEL', '취소'], 'cancelLine' => ['CANCEL', '줄 취소'], 'cancelRun' => ['CANCEL', '실적 취소'], 'cancelComplete' => ['CANCEL', '완료 취소'],
        'cancelLineDone' => ['CANCEL', '줄 완료 취소'], 'cancelInput' => ['CANCEL', '투입 연결 해제'], 'cancelStage' => ['CANCEL', '단계 판정 취소'],
        'issueCancel' => ['CANCEL', '출고 취소'], 'returnCancel' => ['CANCEL', '반납 취소'], 'leftoverCancel' => ['CANCEL', '잔재 입고 취소'],
        'complete' => ['ACTION', '완료'], 'completeAll' => ['ACTION', '모든 공정 완료'], 'approve' => ['ACTION', '승인'], 'reject' => ['ACTION', '반려'],
        'requestApproval' => ['ACTION', '결재 요청'], 'activate' => ['ACTION', '활성 전환'], 'copyVersion' => ['ACTION', '버전 추가'],
        'addRun' => ['SAVE', '실적 입력'], 'addInputs' => ['SAVE', '투입 LOT 연결'], 'stage' => ['SAVE', '단계 판정'],
        'issue' => ['ACTION', '출고'], 'issueSave' => ['ACTION', '자재 출고'], 'returnSave' => ['ACTION', '자재 반납'], 'leftoverSave' => ['ACTION', '잔재 입고'],
        'move' => ['ACTION', '이동'], 'status' => ['ACTION', '상태 변경'], 'scrap' => ['ACTION', '폐기'], 'printed' => ['ACTION', '인쇄'],
        'upload' => ['SAVE', '첨부 올리기'], 'commit' => ['SAVE', '엑셀 일괄 등록'], 'validate' => ['ACTION', '엑셀 검사'],
        'apply' => ['SAVE', '적용'], 'reset' => ['ACTION', '초기화'], 'draft' => ['SAVE', '임시 저장'],
        'quickMaterial' => ['SAVE', '자재 빠른 등록'], 'quickSpec' => ['SAVE', '규격 빠른 등록'],
    ];

    public function handle(Request $request, Closure $next): Response {
        $before = Auth::user();   // 로그아웃은 처리 뒤 사용자가 없다
        $response = $next($request);
        try {
            if ($row = $this->row($request, $response, $before)) {
                DB::table('activity_log')->insert($row);
            }
        } catch (\Throwable $e) {
            Log::warning('활동 로그를 남기지 못함 : ' . $e->getMessage());
        }
        return $response;
    }

    private function row(Request $request, Response $response, $before): ?array {
        $route  = $request->route();
        $name   = (string) ($route?->getName() ?? '');
        $last   = $name === '' ? '' : (string) last(explode('.', $name));
        $method = strtoupper($request->method());
        $status = $response->getStatusCode();

        // 활동 종류
        if ($name === 'user.login.process') {
            $ok = Auth::check();
            [$action, $label] = $ok ? ['LOGIN', '로그인'] : ['LOGIN_FAIL', '로그인 실패'];
        } elseif ($name === 'user.logout') {
            [$action, $label] = ['LOGOUT', '로그아웃'];
        } elseif ($method === 'GET') {
            if ($request->query('export') === '1' || $request->query('export') === 1) {
                [$action, $label] = ['EXPORT', '엑셀 내보내기'];
            } elseif ($route?->isFallback && $status < 400 && !$request->header('X-Inertia-Partial-Data')) {
                [$action, $label] = ['VIEW', '화면 열기'];
            } else {
                return null;   // 목록 · 상세 조회는 남기지 않는다
            }
        } else {
            if (in_array($last, self::SKIP_ROUTES, true)) return null;
            [$action, $label] = self::ACTIONS[$last] ?? ['ACTION', $last !== '' ? $last : $method];
        }

        $user = Auth::user() ?? $before;
        if (!$user && !in_array($action, ['LOGIN_FAIL'], true)) {
            return null;   // 로그인 안 한 요청 ( 로그인 실패만 남긴다 )
        }

        [$menuCode, $menuPath] = $this->menu($request, $route?->isFallback ?? false);
        $ok = $status < 400 && $action !== 'LOGIN_FAIL';

        return [
            'logged_at'   => now(),
            'account_idx' => $user?->account_idx,
            'user_id'     => mb_substr((string) ($user?->user_id ?? $request->input('user_id', '')), 0, 60) ?: null,
            'user_name'   => $user?->user_name ? mb_substr($user->user_name, 0, 60) : null,
            'menu_code'   => $menuCode,
            'menu_path'   => $menuPath,
            'action'      => $action,
            'action_name' => mb_substr($label, 0, 60),
            'route_name'  => $name !== '' ? mb_substr($name, 0, 120) : null,
            'method'      => $method,
            'url'         => mb_substr('/' . ltrim($request->path(), '/'), 0, 300),
            'target'      => in_array($action, ['VIEW', 'LOGIN', 'LOGIN_FAIL', 'LOGOUT', 'EXPORT'], true) ? null : $this->target($request, $response),
            'is_success'  => $ok ? 'Y' : 'N',
            'status_code' => $status,
            'message'     => $ok ? null : $this->failMessage($response, $action),
            'payload'     => in_array($action, ['VIEW', 'LOGOUT'], true) ? null : $this->payload($request),
            'ip'          => $request->ip(),
            'user_agent'  => mb_substr((string) $request->userAgent(), 0, 300) ?: null,
        ];
    }

    // 메뉴 : 권한 검사 메뉴 → 화면 주소 → 요청한 화면
    private function menu(Request $request, bool $isPage): array {
        $menus = $this->menus();
        $codes = (array) $request->attributes->get(self::REQ_MENU, []);
        $code  = null;
        if ($codes) {
            $code = (string) $codes[0];
        } else {
            $path = $isPage ? '/' . ltrim($request->path(), '/') : (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: null);
            if ($path) {
                $code = $menus['byUrl'][rtrim($path, '/') ?: '/'] ?? null;
            }
        }
        return [$code, $code ? ($menus['path'][$code] ?? null) : null];
    }

    // 메뉴 표 ( 요청 하나에 한 번 )
    private ?array $menuCache = null;
    private function menus(): array {
        if ($this->menuCache !== null) return $this->menuCache;
        $rows   = DB::table('system_menu')->get(['menu_code', 'parent_menu_code', 'menu_name', 'url_path']);
        $byCode = $rows->keyBy('menu_code');
        $path   = [];
        foreach ($rows as $r) {
            $names = [$r->menu_name];
            $p = $r->parent_menu_code;
            for ($i = 0; $p && $i < 5; $i++) {
                $parent = $byCode->get($p);
                if (!$parent) break;
                array_unshift($names, $parent->menu_name);
                $p = $parent->parent_menu_code;
            }
            $path[$r->menu_code] = mb_substr(implode(' > ', $names), 0, 200);
        }
        $byUrl = $rows->filter(fn ($r) => $r->url_path)->mapWithKeys(fn ($r) => [rtrim($r->url_path, '/') ?: '/' => $r->menu_code])->all();
        return $this->menuCache = ['path' => $path, 'byUrl' => $byUrl];
    }

    // 대상 : 응답의 문서 번호( *_no ) → 요청의 문서 번호 → 요청의 IDX
    private function target(Request $request, Response $response): ?string {
        $data = $response instanceof JsonResponse ? $response->getData(true) : [];
        foreach ([$data, $request->all()] as $src) {
            foreach ((array) $src as $k => $v) {
                if (is_string($k) && str_ends_with($k, '_no') && is_scalar($v) && $v !== '') {
                    return mb_substr("{$v}", 0, 200);
                }
            }
        }
        foreach ($request->all() as $k => $v) {
            if (is_string($k) && str_ends_with($k, '_idx') && is_scalar($v) && $v !== '' && $v !== null) {
                return mb_substr("{$k}={$v}", 0, 200);
            }
            if (is_string($k) && str_ends_with($k, '_idxs') && is_array($v) && $v) {
                return mb_substr($k . '=' . implode(',', array_slice(array_filter($v, 'is_scalar'), 0, 20)), 0, 200);
            }
        }
        return null;
    }

    private function failMessage(Response $response, string $action): ?string {
        if ($action === 'LOGIN_FAIL') return '아이디 또는 비밀번호가 맞지 않음';
        $data = $response instanceof JsonResponse ? $response->getData(true) : null;
        if (is_array($data)) {
            $first = collect($data['errors'] ?? [])->flatten()->first();
            $msg   = $first ?? ($data['message'] ?? null);
            if ($msg) return mb_substr((string) $msg, 0, 500);
        }
        return 'HTTP ' . $response->getStatusCode();
    }

    private function payload(Request $request): ?string {
        $clean = function ($v, $k = null) use (&$clean) {
            if (is_string($k) && in_array(strtolower($k), self::MASK_KEYS, true)) return '***';
            if ($v instanceof UploadedFile) return '[파일] ' . $v->getClientOriginalName();
            if (is_array($v)) {
                $out = [];
                foreach ($v as $kk => $vv) $out[$kk] = $clean($vv, $kk);
                return $out;
            }
            return $v;
        };
        $data = $clean(array_merge($request->query(), $request->all() + $request->allFiles()));
        if (!$data) return null;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($json) > self::PAYLOAD_MAX ? mb_substr($json, 0, self::PAYLOAD_MAX) . '…( 잘림 )' : $json;
    }
}

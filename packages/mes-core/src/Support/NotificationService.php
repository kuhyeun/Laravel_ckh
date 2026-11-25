<?php

namespace MesCore\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MesCore\Auth\Models\User;
use MesCore\Menu\Services\MenuPermissionService;

/**
 * 알림 보내기 ( 표 notification · 11-13 ) — 받는 사람마다 한 줄
 *   send()          : 받는 사람 목록에게 ( 일으킨 본인은 뺀다 )
 *   usersWithPage() : 그 화면의 권한이 있는 사용 중 사용자 ( 결재 요청 → 승인할 수 있는 사람 )
 *   알림을 못 보내도 업무는 그대로 ( 경고 로그만 ) · 읽은 지 90일 지난 알림은 가끔 지운다
 *   받기 설정 ( notification_pref · 11-23 ) : 그 종류를 끈 사람( is_on = N )은 뺀다 — 행이 없으면 받음 · 알림관리 화면에서 정한다
 */
class NotificationService {

    // 종류 = 업무 + 사건 ( 11-23 — 결재가 출하 말고도 생기면 따로 받기 설정하도록 업무를 키에 넣는다 · 이름은 받는 사람 입장 )
    //   끝말 _REQUEST · _APPROVED · _REJECTED 는 화면 색( 종 · 내 알림 )이 따른다
    public const KINDS = [
        'SHIP_APPROVAL_REQUEST'  => '출하 결재 요청 받음',
        'SHIP_APPROVAL_APPROVED' => '내 출하 결재 승인됨',
        'SHIP_APPROVAL_REJECTED' => '내 출하 결재 반려됨',
    ];

    // 종류 설명 ( 알림관리 받기 설정 화면 ) — when : 언제 · to : 누구에게 · needs : 받으려면 있어야 하는 화면 권한 ( 없으면 켜도 안 온다 )
    public const KIND_INFO = [
        'SHIP_APPROVAL_REQUEST'  => ['when' => '누군가 출하 결재를 요청했을 때', 'to' => '승인할 수 있는 사람', 'needs' => ['Shipment/ShipmentManage', 'can_delete', '출하관리 삭제( 승인 ) 권한']],
        'SHIP_APPROVAL_APPROVED' => ['when' => '내가 요청한 출하 결재가 승인됐을 때', 'to' => '결재를 요청한 사람', 'needs' => ['Shipment/ShipmentManage', 'can_write', '출하관리 쓰기( 결재 요청 ) 권한']],
        'SHIP_APPROVAL_REJECTED' => ['when' => '내가 요청한 출하 결재가 반려됐을 때', 'to' => '결재를 요청한 사람', 'needs' => ['Shipment/ShipmentManage', 'can_write', '출하관리 쓰기( 결재 요청 ) 권한']],
    ];

    private const KEEP_DAYS = 90;

    public function __construct(private MenuPermissionService $perms) {}

    /**
     * @param int[] $to 받는 사람 account_idx
     * @param array{title:string, body?:?string, page?:?string, keyword?:?string, ref_type?:?string, ref_idx?:?int} $msg
     */
    public function send(string $kind, array $to, array $msg): int {
        try {
            $actor = Auth::id();
            $to    = array_values(array_unique(array_filter(array_map('intval', $to), fn ($x) => $x > 0 && $x !== (int) $actor)));
            if (!$to) return 0;
            // 그 종류를 끈 사람은 뺀다 ( 받기 설정 )
            $off = DB::table('notification_pref')->where('kind', $kind)->where('is_on', 'N')->whereIn('account_idx', $to)->pluck('account_idx')->map(fn ($x) => (int) $x)->all();
            $to  = array_values(array_diff($to, $off));
            if (!$to) return 0;
            $now  = now();
            $rows = array_map(fn ($idx) => [
                'account_idx'    => $idx,
                'kind'           => $kind,
                'title'          => mb_substr($msg['title'], 0, 200),
                'body'           => isset($msg['body']) ? mb_substr((string) $msg['body'], 0, 500) : null,
                'page_component' => $msg['page'] ?? null,
                'keyword'        => isset($msg['keyword']) ? mb_substr((string) $msg['keyword'], 0, 100) : null,
                'ref_type'       => $msg['ref_type'] ?? null,
                'ref_idx'        => $msg['ref_idx'] ?? null,
                'actor_idx'      => $actor,
                'created_at'     => $now,
                'read_at'        => null,
            ], $to);
            DB::table('notification')->insert($rows);
            if (random_int(1, 50) === 1) {
                DB::table('notification')->whereNotNull('read_at')->where('read_at', '<', $now->copy()->subDays(self::KEEP_DAYS))->delete();
            }
            return count($rows);
        } catch (\Throwable $e) {
            Log::warning("알림을 보내지 못함 ( {$kind} ) : " . $e->getMessage());
            return 0;
        }
    }

    /** @return int[] 그 화면에 $action 권한이 있는 사용 중 사용자 */
    public function usersWithPage(string $page, string $action): array {
        return User::where('is_use', 'Y')->get()
            ->filter(fn (User $u) => $this->perms->canPage($u, $page, $action))
            ->map(fn (User $u) => (int) $u->account_idx)->values()->all();
    }
}

<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Basic\Services\PolicyService;
use MesCore\Http\Controller;

// 모니터링 화면 설정 ( 11-26 ) — 장 넘김 시간( 초 ) · 모든 모니터링 화면 공통 ( 시스템 설정 monitor.slide_sec )
//   읽기 : 로그인한 누구나 ( 모니터링 화면이 연다 ) · 저장 : 시스템 계정( 레벨 0 )만 — 모니터링 화면 머리 왼쪽 [⚙]
class MonitorSettingController extends Controller {

    public const MIN_SEC = 3;
    public const MAX_SEC = 600;

    public function __construct(private PolicyService $policy) {}

    public function show() {
        return response()->json(['slide_sec' => $this->slideSec()]);
    }

    public function save(Request $request) {
        abort_unless((int) (Auth::user()?->user_level ?? 99) === 0, 403, '시스템 계정만 바꿀 수 있습니다.');
        $v = $request->validate(
            ['slide_sec' => 'required|integer|min:' . self::MIN_SEC . '|max:' . self::MAX_SEC],
            [], ['slide_sec' => '장 넘김 시간']
        );
        $this->policy->set('monitor.slide_sec', (string) $v['slide_sec'], 'TENANT', 0, '모니터링 화면에서 변경');
        return response()->json(['message' => 'Success', 'slide_sec' => $this->slideSec()]);
    }

    private function slideSec(): int {
        $n = (int) ($this->policy->get('monitor.slide_sec') ?? 10);
        return max(self::MIN_SEC, min(self::MAX_SEC, $n ?: 10));
    }
}

<?php

namespace MesCore\Support\Logging;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;

// 로그 줄마다 요청 정보 — u=사용자 IDX( 로그인 전 - ) · 메서드 · 주소 · IP / 콘솔이면 cli
//   사용자 IDX 는 DB 를 읽지 않고 얻는다 ( 11-11 고침 ) :
//     전에는 Auth::id() → 아직 안 읽은 사용자를 DB 에서 읽음 → 그 쿼리가 sql.log 를 남기며 다시 이 처리기 → 또 Auth::id() …
//     → Monolog 가 "infinite logging loop" 로 끊고 경고를 남겼다 ( 요청마다 2번 · 사용자 조회 쿼리도 겹쳐 남음 )
//   이미 읽은 사용자가 있으면 그 IDX · 없으면 세션에 든 로그인 IDX ( 세션 가드 ) · 그 밖은 -
class RequestContextTap {

    public function __invoke(Logger $logger): void {
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();
        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            $extra = ['u' => self::userId()];
            if (app()->runningInConsole()) {
                $extra['req'] = 'cli';
            } else {
                $r = request();
                $extra['req'] = $r->method() . ' ' . $r->getRequestUri();
                $extra['ip']  = $r->ip();
            }
            return $record->with(extra: array_merge($record->extra, $extra));
        });
    }

    private static function userId(): int|string {
        try {
            $guard = Auth::guard();
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                return $guard->id() ?? '-';
            }
            // 세션 가드 : 로그인 IDX 는 세션에 있다 ( 사용자 행을 읽지 않는다 )
            if (method_exists($guard, 'getName') && app()->bound('session.store')) {
                return app('session.store')->get($guard->getName()) ?? '-';
            }
        } catch (\Throwable $e) {
            // 로그 때문에 원래 처리가 깨지지 않게
        }
        return '-';
    }
}

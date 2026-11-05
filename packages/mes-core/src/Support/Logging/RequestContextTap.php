<?php

namespace MesCore\Support\Logging;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;

// 로그 줄마다 요청 정보 — u=사용자 IDX( 로그인 전 - ) · 메서드 · 주소 · IP / 콘솔이면 cli
class RequestContextTap {

    public function __invoke(Logger $logger): void {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            $extra = [];
            try {
                $extra['u'] = Auth::id() ?? '-';
            } catch (\Throwable $e) {
                $extra['u'] = '-';
            }
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
}

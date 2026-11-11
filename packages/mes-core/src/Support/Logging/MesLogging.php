<?php

namespace MesCore\Support\Logging;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;

/**
 * MES 로그 ( 날짜별 폴더 — storage/logs/{YYYY-MM-DD}/ ) — config/mes.php 'log'
 *   sql.log   : 실행한 쿼리 전부( 값을 채운 SQL · 걸린 시간 ) + 실패한 쿼리( SQL · 오류 )      — mes_sql
 *   error.log : 모든 오류( 예외 · 스택 · 쿼리 오류 포함 · Log::error 이상 )                    — mes_error ( 기본 stack 에 늘 붙인다 )
 *   app.log   : 그 밖의 일반 로그 ( LOG_STACK=mes_app 로 쓸 때 — laravel.log 대신 )            — mes_app
 *   줄마다 요청 정보( 사용자 · 메서드 · 주소 · IP )를 붙인다
 */
class MesLogging {

    // sql.log 를 쓰는 중 ( 그 사이 생긴 쿼리는 남기지 않는다 )
    private static bool $writing = false;

    // 채널 등록 ( ServiceProvider::register — 설정을 읽기 전에 )
    public static function registerChannels(): void {
        $root = storage_path('logs');
        $days = (int) config('mes.log.days', 30);
        $tap  = [RequestContextTap::class];
        foreach (['mes_sql' => ['sql', 'debug'], 'mes_error' => ['error', 'error'], 'mes_app' => ['app', 'debug']] as $ch => [$file, $level]) {
            config(["logging.channels.{$ch}" => [
                'driver' => 'custom',
                'via'    => DailyFolderLogger::class,
                'name'   => $file,
                'root'   => $root,
                'days'   => $days,
                'level'  => $level === 'debug' ? env('LOG_LEVEL', 'debug') : $level,
                'tap'    => $tap,
            ]]);
        }
        // 모든 오류는 error.log 에도 — 기본 stack 에 늘 붙인다 ( 업체 설정과 상관없이 )
        $stack = config('logging.channels.stack.channels', []);
        if (is_array($stack) && !in_array('mes_error', $stack, true)) {
            config(['logging.channels.stack.channels' => array_merge($stack, ['mes_error'])]);
        }
    }

    // 쿼리 듣기 · 쿼리 오류 ( ServiceProvider::boot )
    public static function boot($app): void {
        if (config('mes.log.sql', true)) {
            $skip = config('mes.log.sql_skip_tables', []);
            DB::listen(function (QueryExecuted $q) use ($skip) {
                // 로그를 남기는 도중에 생긴 쿼리는 남기지 않는다 ( 되돌아 들어와 끝없이 도는 것 방지 — 11-11 )
                if (self::$writing) {
                    return;
                }
                if ($skip && preg_match('/\b(?:from|into|update|join)\s+`?(' . implode('|', array_map('preg_quote', $skip)) . ')`?\b/i', $q->sql)) {
                    return;
                }
                self::$writing = true;
                try {
                    Log::channel('mes_sql')->info(sprintf('(%.2fms) %s', $q->time, self::interpolate($q->sql, $q->bindings, $q->connection->getPdo())));
                } finally {
                    self::$writing = false;
                }
            });
        }

        // 쿼리 오류 : sql.log 에 SQL · 오류 ( error.log 에는 기본 보고가 스택과 함께 남긴다 )
        $app->make(ExceptionHandler::class)->reportable(function (QueryException $e) {
            try {
                Log::channel('mes_sql')->error('QUERY ERROR ' . $e->getPrevious()?->getMessage() . "\n    SQL : "
                    . self::interpolate($e->getSql(), $e->getBindings(), null));
            } catch (\Throwable $ignore) {
                // 로그를 못 남겨도 원래 오류 처리는 그대로
            }
        });
    }

    // 값을 채운 SQL ( 읽기용 — 실행하지 않는다 )
    public static function interpolate(string $sql, array $bindings, $pdo): string {
        $max = (int) config('mes.log.sql_max_length', 5000);
        $i   = 0;
        $out = preg_replace_callback('/\?/', function () use (&$i, $bindings, $pdo) {
            if (!array_key_exists($i, $bindings)) {
                return '?';
            }
            $v = $bindings[$i++];
            return match (true) {
                self::isSecret($v)                   => "'***'",   // 비밀번호 해시 · 로그인 유지 토큰은 남기지 않는다 ( 11-09 코드 검사 A-2 )
                $v === null                          => 'NULL',
                is_bool($v)                          => $v ? '1' : '0',
                is_int($v) || is_float($v)           => (string) $v,
                $v instanceof \DateTimeInterface     => "'" . $v->format('Y-m-d H:i:s') . "'",
                default                              => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], mb_strimwidth((string) $v, 0, 500, '…')) . "'",
            };
        }, $sql);
        return mb_strlen($out) > $max ? mb_substr($out, 0, $max) . ' …( 잘림 )' : $out;
    }

    // 로그에 남기면 안 되는 값 — bcrypt · argon 해시( 비밀번호 ) · 60자 무작위 토큰( remember_token )
    private static function isSecret($v): bool {
        if (!is_string($v)) {
            return false;
        }
        return (bool) preg_match('/^\$(2[aby]|argon2(i|id))\$/', $v) || (strlen($v) === 60 && ctype_alnum($v));
    }
}

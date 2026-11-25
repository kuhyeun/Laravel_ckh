<?php

namespace MesCore\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 날짜 · 일시 수정 공통 ( 11-25 ) — 등록한 기록의 날짜 · 일시만 고친다 ( 수량 · LOT · 상태는 그대로 )
 *   - 라우트 이름 끝은 editDate ( 활동 로그 '날짜 수정' )
 *   - remember() : 바꾸기 전 값을 활동 로그 payload 의 _before 로 남긴다 ( ActivityLogger )
 *   - date() / dateTime() : 보낸 값을 읽는다 ( 비었거나 형식이 틀리면 그 칸 422 )
 *   - 재고 원장 일시는 StockService::checkBackdate 로 등록 때와 같은 규칙( 미래 · 소급 차단 )을 건다
 *   - 화면 : Components/Common/DateEditDialog.vue
 */
class DateEdit {

    public const BEFORE = 'activity.before';

    public static function remember(array $before): void {
        request()->attributes->set(self::BEFORE, $before);
    }

    // 'YYYY-MM-DD'
    public static function date(mixed $value, string $field, string $label): Carbon {
        $v = trim((string) $value);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            throw ValidationException::withMessages([$field => ["{$label}을( 를 ) 넣으세요."]]);
        }
        try {
            return Carbon::createFromFormat('Y-m-d', substr($v, 0, 10))->startOfDay();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([$field => ["{$label} 형식이 맞지 않습니다."]]);
        }
    }

    // 'YYYY-MM-DD HH:mm( :ss )' — 초는 버린다
    public static function dateTime(mixed $value, string $field, string $label): Carbon {
        $v = trim(str_replace('T', ' ', (string) $value));
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v)) {
            throw ValidationException::withMessages([$field => ["{$label}을( 를 ) 넣으세요."]]);
        }
        try {
            return Carbon::createFromFormat('Y-m-d H:i', substr($v, 0, 16))->second(0);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([$field => ["{$label} 형식이 맞지 않습니다."]]);
        }
    }

    // 시작 ≤ 끝 ( 공정실적 시작 · 종료 등 )
    public static function order(?Carbon $start, Carbon $end, string $field, string $message = '시작 시간이 종료 시간보다 늦습니다.'): void {
        if ($start && $start->gt($end)) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }
}

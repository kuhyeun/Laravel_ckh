<?php

namespace MesCore\Support;

/**
 * 수량 문자열 ( 11-09 코드 검사 C-1 — 서버 17곳에 같은 함수가 복사돼 있었다 )
 *   str() : 저장 · SQL 용 ( 소수 6자리까지 · 끝 0 떼기 · 지수 표기 없음 )  1234.5 → '1234.5'
 *   num() : 문구 용 ( 천 단위 쉼표 · 끝 0 떼기 )                           1234.5 → '1,234.5'
 */
trait FormatsQty {

    protected function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    protected function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.') ?: '0';
    }
}

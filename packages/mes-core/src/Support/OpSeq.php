<?php

namespace MesCore\Support;

use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Process;

// 작업지시 공정 순번 ( 11-26 ) — 저장 · 표시 규칙 한 곳
//   저장 seq : 원자재출고를 뺀 공정 = 10 · 20 · 30 … ( 순서대로 ) / 원자재출고 = 그 사이 값 ( 앞 공정 seq + 5 — 첫 공정이면 5 ) — 정렬만 맞으면 된다
//   표시     : 원자재출고 = 시스템 설정 production.rm_out_seq_label ( 기본 「M」 ) / 그 밖 = seq 세 자리 ( 010 )
//   실적 LOT 번호의 공정 순번( -P010- )도 이 표시를 쓴다 ( OpResultService )
final class OpSeq {

    private static ?string $rmLabel = null;

    // 원자재출고 순번 문자
    public static function rmLabel(): string {
        if (self::$rmLabel === null) {
            try {
                $v = trim((string) app(PolicyService::class)->get('production.rm_out_seq_label'));
            } catch (\Throwable $e) {
                $v = '';
            }
            self::$rmLabel = $v !== '' ? $v : 'M';
        }
        return self::$rmLabel;
    }

    // 순번 표시 — $rm : 원자재출고 공정인지 ( 모델 isRmOut() · 조회 행이면 isRmKey( system_key ) )
    public static function label($seq, bool $rm): string {
        return $rm ? self::rmLabel() : str_pad((string) (int) $seq, 3, '0', STR_PAD_LEFT);
    }

    // 「순번 공정이름」
    public static function name($seq, bool $rm, ?string $processName): string {
        return trim(self::label($seq, $rm) . ' ' . ($processName ?? ''));
    }

    // 조회 행( p.system_key )으로 원자재출고인지
    public static function isRmKey(?string $systemKey): bool {
        return $systemKey === Process::SYS_RM_OUT;
    }

    // 저장 seq 매기기 — 공정 목록 순서대로 원자재출고인지 [ bool, … ] → [ seq, … ]
    public static function assign(array $isRm): array {
        $out = [];
        $n = 0;     // 원자재출고를 뺀 공정 수
        $rm = 0;    // 바로 앞 공정 뒤에 이어진 원자재출고 수 ( 겹치지 않게 )
        foreach ($isRm as $i => $r) {
            if ($r) {
                $out[$i] = $n * 10 + 5 + $rm;
                $rm++;
            } else {
                $out[$i] = ++$n * 10;
                $rm = 0;
            }
        }
        return $out;
    }
}

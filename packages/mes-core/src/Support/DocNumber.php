<?php

namespace MesCore\Support;

use Illuminate\Support\Facades\DB;

/**
 * 문서 번호 ( XX-YYMMDD-NNN ) — 머리( 'SO-261109-' )마다 순번 표 doc_seq 를 잠그고 +1 ( 11-09 코드 검사 B-4 · C-3 )
 *   전에는 8곳이 각자 "그 날짜의 마지막 번호를 LIKE … FOR UPDATE 로 잠그고 +1" 을 했다
 *   → 그날 첫 번호는 잠글 행이 없어 갭 락만 잡혀, 두 요청이 동시에 오면 INSERT 에서 데드락( 500 )이 났다
 *   이제 순번 행을 먼저 만들고( insertOrIgnore — 처음이면 그 표의 지금 마지막 번호부터 ) 그 행을 잠근다 ( LOT 순번 lot_seq 와 같은 방식 )
 *   트랜잭션 안에서 부른다 ( 번호를 쓴 행 저장과 같이 커밋 )
 *
 *   DocNumber::next('SO-261109-', 'sales_order', 'order_no')   → SO-261109-001
 */
class DocNumber {

    public static function next(string $head, string $table, string $column, int $pad = 3): string {
        // 처음 쓰는 머리 : 그 표의 지금 마지막 번호에서 시작 ( 순번 표를 새로 깐 DB · 예전 번호와 이어지게 )
        if (!DB::table('doc_seq')->where('seq_key', $head)->exists()) {
            $last = DB::table($table)->where($column, 'like', addcslashes($head, '%_\\') . '%')->orderByDesc($column)->value($column);
            DB::table('doc_seq')->insertOrIgnore(['seq_key' => $head, 'last_seq' => $last ? (int) substr($last, strlen($head)) : 0]);
        }

        $seq = (int) DB::table('doc_seq')->where('seq_key', $head)->lockForUpdate()->value('last_seq') + 1;
        DB::table('doc_seq')->where('seq_key', $head)->update(['last_seq' => $seq]);

        return $head . str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
    }
}

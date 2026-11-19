<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 초 · 중 · 종 검사를 생산 공정에 붙인다 ( 11-18 )
//   전 : 초중종 = 검사 공정의 한 종류( process.inspection_kind = IFL ) — 라우팅에 따로 한 줄. 생산 공정과 떨어져 있었다
//   후 : 검사 공정은 모두 일반 검사( 앞 공정이 끝난 뒤 합격 · 불합격 수량 ) 하나뿐
//        초 · 중 · 종은 생산 공정( 생산 · 설비생산 )의 옵션 — 그 공정을 시작할 때 · 도중 · 끝난 뒤의 판정 기록
//        process.insp_first · insp_middle · insp_last = 기본값 → 라우팅 줄 · 작업지시 공정이 받아 간다 ( 줄마다 고칠 수 있다 )
//        판정은 품질관리 > 공정검사에서만 넣는다 · 생산( 실적 · 완료 )을 막지 않는다 · 불량 수량은 실적에서 빼지 않고 불량 이력으로만
//   기존 데이터 : 검사 공정 줄에 켜져 있던 초중종 · 선행 진행( 종물 전 승인 — 이제 막지 않으니 뜻이 없다 )은 끈다
return new class extends Migration {

    public function up(): void {
        Schema::table('process', function (Blueprint $table) {
            $table->enum('insp_first', ['Y', 'N'])->default('N')->after('inspection_kind')->comment('초물 검사 기본값 ( 생산 공정 )');
            $table->enum('insp_middle', ['Y', 'N'])->default('N')->after('insp_first')->comment('중물 검사 기본값');
            $table->enum('insp_last', ['Y', 'N'])->default('N')->after('insp_middle')->comment('종물 검사 기본값');
        });

        DB::table('process')->where('process_type', 'INSPECTION')->update(['inspection_kind' => 'NORMAL']);

        $inspection = DB::table('process')->where('process_type', 'INSPECTION')->pluck('process_idx');
        foreach (['routing_line', 'work_order_op'] as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->whereIn('process_idx', $inspection)->update(['insp_first' => 'N', 'insp_middle' => 'N', 'insp_last' => 'N']);
                DB::table($t)->where(fn ($q) => $q->where('pre_approve_min', '>', 0)->orWhere('pre_approved_by_design', 'Y'))
                    ->update(['pre_approve_min' => 0, 'pre_approved_by_design' => 'N']);
            }
        }
    }

    public function down(): void {
        Schema::table('process', function (Blueprint $table) {
            $table->dropColumn(['insp_first', 'insp_middle', 'insp_last']);
        });
    }
};

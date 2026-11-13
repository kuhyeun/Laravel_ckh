<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 공정 실적 시작 시간 ( 11-11 회의 — 실적 입력 때 시작 · 종료 )
//   worked_at = 종료( 작업 일시 — 지금까지처럼 정렬 · 기간 기준 ) / started_at = 시작 ( 화면 기본 = 종료 − 2시간 · 예전 실적 · 외주 실적은 비어 있음 )
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable()->after('qty')->comment('작업 시작 일시 ( worked_at = 종료 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};

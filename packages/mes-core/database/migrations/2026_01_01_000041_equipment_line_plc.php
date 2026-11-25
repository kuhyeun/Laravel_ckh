<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 설비 라인 · PLC 코드 ( 11-25 · MES_1125 #3 ) — 지금은 저장만 ( 라인 = 나중에 묶어 보기 · PLC 코드 = 모니터링 연동 )
//   line_code : 공통코드 EQUIPMENT_LINE 의 코드키
//   plc_code  : 관리자( 레벨 0 · 1 )만 보고 고친다 ( 화면 · 응답 모두 )
return new class extends Migration {

    public function up(): void {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('line_code', 100)->nullable()->after('equipment_name')->comment('라인 ( 공통코드 EQUIPMENT_LINE )');
            $table->string('plc_code', 50)->nullable()->after('line_code')->comment('모니터링용 PLC 코드 ( 관리자 )');
            $table->index('line_code');
        });
    }

    public function down(): void {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['line_code']);
            $table->dropColumn(['line_code', 'plc_code']);
        });
    }
};

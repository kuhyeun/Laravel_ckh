<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // ADR 0019: 참조되는 코드는 지우지 않고 내린다( is_use = N ) → 새 입력 드롭다운에서 제외, 과거 데이터는 유지
    // sort_order: 드롭다운 표시 순서 ( 같으면 코드키 순 )
    public function up(): void {
        Schema::table('system_code', function (Blueprint $table) {
            $table->enum('is_use', ['Y', 'N'])->default('Y')->after('system_code_desc')->comment('사용 여부 ( N: 신규 선택 목록에서 제외 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_use')->comment('정렬 순서 ( 오름차순, 같으면 코드키 순 )');
        });
    }

    public function down(): void {
        Schema::table('system_code', function (Blueprint $table) {
            $table->dropColumn(['is_use', 'sort_order']);
        });
    }
};

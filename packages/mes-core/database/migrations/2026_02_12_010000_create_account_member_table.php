<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * up : 실행 부분 ( COMMIT )
 * down: 실패시 예외처리 ( ROLLBACK )
 */

return new class extends Migration {
    
    public function up(): void {
        Schema::create('account_member', function (Blueprint $table) {
            $table->id('account_idx')->comment('사용자 IDX');
            $table->string('user_id', 50)->comment('사용자 ID')->index();
            $table->string('user_pw', 300)->comment('사용자 PW ( hashed )');
            $table->string('user_name', 50)->default('')->comment('사용자 이름');
            $table->string('user_type', 20)->default('')->comment('사용자 종류');
            $table->integer('user_level')->default(99)->comment('0: 시스템 관리자, 1: 관리자, 10: 중간관리자, 99: 사용자');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->default(0)->comment('생성자');
            $table->timestamp('create_datetime')->useCurrent()->comment('생성일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('사용자 테이블');
        });
    }

    public function down(): void {
        Schema::dropIfExists('account_member');
    }
};

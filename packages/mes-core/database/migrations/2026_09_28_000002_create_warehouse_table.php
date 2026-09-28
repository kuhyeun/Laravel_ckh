<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 창고 ( BAS-06 · ERD §6 )
//   자기참조 계층 ( 자재창고 > 1구역 ). 재고는 잎 노드( 하위가 없는 창고 )에 붙는다
return new class extends Migration {

    public function up(): void {
        Schema::create('warehouse', function (Blueprint $table) {
            $table->id('warehouse_idx')->comment('창고 IDX');
            $table->string('warehouse_code', 30)->unique()->comment('창고코드 ( 유일 )');
            $table->string('warehouse_name', 60)->comment('창고명');
            // 상위 창고 : 하위가 있으면 삭제( 물리 )되지 않도록 제한 — 미사용 처리만 한다
            $table->foreignId('parent_idx')->nullable()->comment('상위 창고 IDX ( NULL = 최상위 )')->constrained(
                table: 'warehouse',
                column: 'warehouse_idx'
            )->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('같은 상위 안에서의 표시 순서');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['parent_idx', 'sort_order']);
            $table->comment('창고 ( 다단계 로케이션 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('warehouse');
    }
};

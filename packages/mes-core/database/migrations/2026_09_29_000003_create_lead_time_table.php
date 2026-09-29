<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 제조리드타임 ( BAS-09 ) — docs/screens/20-기준정보.md · ERD "리드타임은 두 축이다"
//   lead_time : 5구간 소요 일수 ( 수량과 무관 ). 개당 시간( routing_line.cycle_time_sec )과는 다른 축
//   기본값은 item_idx = NULL 인 행 하나 ( 문서의 item_id = 0 관례 대신 — FK 를 유지하기 위해 )
//     기본값이 하나뿐인 것은 저장 로직이 보장한다 ( MySQL UNIQUE 는 NULL 중복을 막지 않음 )
//   품목 행이 있으면 그 행만 쓰고 빈 칸은 0( 해당 없음 ), 품목 행이 없으면 기본값
//   실적으로 재계산하는 기능은 Phase 2 — 여기서는 등록만
return new class extends Migration {

    public function up(): void {
        Schema::create('lead_time', function (Blueprint $table) {
            $table->id('lead_time_idx')->comment('리드타임 IDX');
            // 품목은 미사용 처리만 하므로 참조 중 물리 삭제를 막는다
            $table->foreignId('item_idx')->nullable()->unique()->comment('품목 IDX ( NULL = 기본값 )')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            $table->unsignedSmallInteger('purchase_days')->nullable()->comment('구매 일수 ( NULL = 해당 없음 )');
            $table->unsignedSmallInteger('receive_days')->nullable()->comment('입고 일수');
            $table->unsignedSmallInteger('produce_days')->nullable()->comment('생산 일수');
            $table->unsignedSmallInteger('inspect_days')->nullable()->comment('검사 일수');
            $table->unsignedSmallInteger('pack_days')->nullable()->comment('포장 일수');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 미사용이면 기본값을 씀, 기본값 행은 내릴 수 없음 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->comment('제조리드타임');
        });
    }

    public function down(): void {
        Schema::dropIfExists('lead_time');
    }
};

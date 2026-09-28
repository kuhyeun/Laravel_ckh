<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 공정라우팅 ( BAS-05 · ERD §6 )
//   routing      : 라우팅 머리 ( 기본 라우팅은 하나 — 품목 등록 시점에만 자동 적용 )
//   routing_line : 공정 순서. 같은 공정 중복 허용
//     BOM 의 투입 지점이 이 줄의 IDX 를 가리키므로( 순번이 아님 ) 순서를 바꿔도 참조가 유지된다
return new class extends Migration {

    public function up(): void {
        Schema::create('routing', function (Blueprint $table) {
            $table->id('routing_idx')->comment('라우팅 IDX');
            $table->string('routing_code', 30)->unique()->comment('라우팅코드 ( 유일 )');
            $table->string('routing_name', 60)->comment('라우팅명');
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('기본 라우팅 여부 ( 하나만 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->comment('공정 라우팅');
        });

        Schema::create('routing_line', function (Blueprint $table) {
            $table->id('routing_line_idx')->comment('라우팅 줄 IDX ( BOM 투입 지점이 참조 )');
            $table->foreignId('routing_idx')->comment('라우팅 IDX')->constrained(
                table: 'routing',
                column: 'routing_idx'
            )->onDelete('cascade');
            $table->unsignedSmallInteger('seq')->comment('순서 ( 1부터 )');
            // 공정은 미사용 처리만 하므로 참조 중 물리 삭제를 막는다
            $table->foreignId('process_idx')->comment('공정 IDX')->constrained(
                table: 'process',
                column: 'process_idx'
            )->restrictOnDelete();
            $table->decimal('cycle_time_sec', 10, 2)->default(0)->comment('개당 표준시간 ( 초 ) — 수량 기반 일정의 원본');
            // 초 · 중 · 종은 개별로 켠다 ( 초물과 종물만 하는 현장이 있음 ). 켜진 것 중 마지막이 완료 기준
            $table->enum('insp_first', ['Y', 'N'])->default('N')->comment('초물 검사');
            $table->enum('insp_middle', ['Y', 'N'])->default('N')->comment('중물 검사');
            $table->enum('insp_last', ['Y', 'N'])->default('N')->comment('종물 검사');
            // 종물 전에 다음 공정으로 나가는 길 ( 0 = 불가 )
            $table->unsignedTinyInteger('pre_approve_min')->default(0)->comment('선행 진행에 필요한 승인 수 ( 0 = 불가 )');
            $table->enum('pre_approved_by_design', ['Y', 'N'])->default('N')->comment('설계 승인 ( 매번 묻지 않음 )');
            $table->integer('pre_approved_by')->nullable()->comment('설계 승인을 켠 사람');
            $table->timestamp('pre_approved_at')->nullable()->comment('설계 승인을 켠 시각');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['routing_idx', 'seq']);
            $table->comment('라우팅 공정 줄');
        });
    }

    public function down(): void {
        Schema::dropIfExists('routing_line');
        Schema::dropIfExists('routing');
    }
};

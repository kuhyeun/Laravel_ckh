<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BOM ( BAS-03 · ERD §6 )
//   bom      : 품목 × 버전. 활성은 품목당 하나. 날짜가 셋( 변경일 · 승인일 · 활성 전환일 )
//   bom_line : 자재 줄. 투입 지점은 라우팅의 "줄"을 가리킨다( 공정 마스터가 아님 )
return new class extends Migration {

    public function up(): void {
        Schema::create('bom', function (Blueprint $table) {
            $table->id('bom_idx')->comment('BOM IDX');
            // 품목은 미사용 처리만 하므로 참조 중 물리 삭제를 막는다
            $table->foreignId('item_idx')->comment('품목 IDX ( 이 BOM 의 주인 )')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            $table->string('version', 20)->comment('버전 ( 자유 문자열 : V1 · A · 1.0 )');
            $table->enum('is_active', ['Y', 'N'])->default('N')->comment('활성 여부 ( 품목당 하나 )');
            $table->timestamp('activated_at')->nullable()->comment('활성으로 돌린 시각 ( 시스템 )');
            // 설계변경 발효일 : 업체가 직접 적는다. 미래 날짜 허용 ( "다음 달 1일부터 적용" )
            $table->date('changed_at')->nullable()->comment('설계변경 발효일 ( 업체 입력 )');
            $table->timestamp('approved_at')->nullable()->comment('변경 승인일 ( [승인] 누른 시각 )');
            $table->integer('approved_by')->nullable()->comment('변경 승인자');
            $table->text('remark')->nullable()->comment('변경 사유 · 비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['item_idx', 'version']);
            $table->index(['item_idx', 'is_active']);
            $table->comment('BOM ( 품목 × 버전 )');
        });

        Schema::create('bom_line', function (Blueprint $table) {
            $table->id('bom_line_idx')->comment('BOM 줄 IDX');
            $table->foreignId('bom_idx')->comment('BOM IDX')->constrained(
                table: 'bom',
                column: 'bom_idx'
            )->onDelete('cascade');
            $table->unsignedSmallInteger('seq')->default(0)->comment('표시 순서');
            $table->foreignId('child_item_idx')->comment('자재 품목 IDX')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            // 투입 지점이자 소요 조건 : 그 줄이 작업지시에 없으면 자재도 소요가 없다 ( 비우면 언제나 소요 )
            //   라우팅 줄이 삭제되면 NULL 로 — 화면에서 다시 지정한다
            $table->foreignId('routing_line_idx')->nullable()->comment('투입 지점 ( 라우팅 줄. NULL = 첫 수량 공정 )')->constrained(
                table: 'routing_line',
                column: 'routing_line_idx'
            )->nullOnDelete();
            $table->decimal('qty', 15, 4)->comment('소요 ( 제품 1개당 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 비우면 품목 → 정책 순 )');
            $table->decimal('ext_n01', 15, 3)->nullable()->comment('기능형 값');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['bom_idx', 'seq']);
            $table->comment('BOM 자재 줄');
        });
    }

    public function down(): void {
        Schema::dropIfExists('bom_line');
        Schema::dropIfExists('bom');
    }
};

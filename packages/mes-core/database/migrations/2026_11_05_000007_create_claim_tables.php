<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 클레임관리 ( 7004 · 11-05 ) — 출하 완료 건에 연결한 클레임 이력 ( 기록만 — 재고 · 출하 수량은 건드리지 않는다 )
//   claim      : 출하 1건 = 클레임 머리 ( 접수일 · 비고 )
//   claim_line : 그 출하의 품목 줄마다 클레임 수량 · 내용 — 첨부는 줄마다 ( 첨부 대상 claim_line )
return new class extends Migration {

    public function up(): void {
        Schema::create('claim', function (Blueprint $table) {
            $table->id('claim_idx')->comment('클레임 IDX');
            $table->string('claim_no', 30)->unique()->comment('클레임 번호 ( CL-YYMMDD-NNN )');
            $table->unsignedBigInteger('shipment_idx')->comment('출하 ( 완료 )');
            $table->unsignedBigInteger('partner_idx')->comment('고객사 ( 출하 그대로 )');
            $table->date('claim_date')->comment('접수일');
            $table->string('remark', 2000)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->index('shipment_idx');
            $table->index(['partner_idx', 'claim_date']);
            $table->comment('클레임 ( 출하 건 )');
        });

        Schema::create('claim_line', function (Blueprint $table) {
            $table->id('claim_line_idx')->comment('클레임 줄 IDX');
            $table->unsignedBigInteger('claim_idx')->comment('클레임');
            $table->unsignedBigInteger('shipment_line_idx')->comment('출하 품목 줄');
            $table->unsignedBigInteger('item_idx')->comment('품목 ( 출하 줄 그대로 )');
            $table->decimal('qty', 18, 6)->comment('클레임 수량 ( 출하 수량 이하 )');
            $table->string('claim_type_code', 30)->nullable()->comment('클레임 유형 ( 코드 DEFECT_TYPE — 선택 )');
            $table->string('content', 500)->nullable()->comment('내용');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->unique(['claim_idx', 'shipment_line_idx']);
            $table->index(['item_idx']);
            $table->comment('클레임 품목 줄 ( 첨부 대상 claim_line )');
        });

        DB::table('system_menu')->where('menu_code', '7004')->update([
            'menu_name' => '클레임관리', 'url_path' => '/shipment/claim', 'page_component' => 'Shipment/ClaimManage',
        ]);
    }

    public function down(): void {
        DB::table('system_menu')->where('menu_code', '7004')->update(['menu_name' => '클레임관리 ( 미개발 )', 'url_path' => null, 'page_component' => null]);
        Schema::dropIfExists('claim_line');
        Schema::dropIfExists('claim');
    }
};

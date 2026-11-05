<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 외주관리 ( 4005 ) — SubconService
//   subcon_issue   : 외주 출고 ( 지시 공정 하나 · 외주처 · 수량 · 외주 단가 / 금액 — 기록만 )
//   subcon_receipt : 외주 입고 ( 출고 건마다 여러 번 · 검사대기 → 검사 → 완료 / 검사 안 쓰면 바로 완료 ) — 합격분이 외주 공정 실적
//   inspection.insp_type 에 SUBCON · wip_txn 에 외주 출고 연결 · 메뉴 4005 하위 4개 연결
return new class extends Migration {

    public function up(): void {
        Schema::create('subcon_issue', function (Blueprint $table) {
            $table->id('subcon_issue_idx')->comment('외주 출고 IDX');
            $table->string('issue_no', 30)->unique()->comment('출고 번호 ( SC-YYMMDD-NNN )');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시( 품목 줄 )');
            $table->unsignedBigInteger('work_order_op_idx')->comment('외주 공정');
            $table->unsignedBigInteger('partner_idx')->comment('외주처');
            $table->decimal('qty', 18, 6)->comment('출고 수량');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('외주 단가 ( 기록만 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 = 수량 × 단가');
            $table->dateTime('issued_at')->comment('출고 일시');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('출고 취소 ( 입고가 없을 때만 )');
            $table->integer('canceled_by')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->index(['work_order_op_idx', 'is_canceled']);
            $table->index('issued_at');
            $table->comment('외주 출고');
        });

        Schema::create('subcon_receipt', function (Blueprint $table) {
            $table->id('subcon_receipt_idx')->comment('외주 입고 IDX');
            $table->string('receipt_no', 30)->unique()->comment('입고 번호 ( SR-YYMMDD-NNN )');
            $table->unsignedBigInteger('subcon_issue_idx')->comment('외주 출고');
            $table->decimal('qty', 18, 6)->comment('입고 수량 ( 외주처에서 돌아온 수량 )');
            $table->decimal('pass_qty', 18, 6)->nullable()->comment('합격 = 실적에 들어간 수량');
            $table->decimal('fail_qty', 18, 6)->nullable()->comment('불합격 ( 불량 기록 )');
            $table->enum('status', ['WAIT', 'DONE', 'CANCELED'])->comment('WAIT 검사대기 / DONE 입고 완료 / CANCELED 취소');
            $table->dateTime('received_at')->comment('입고 일시');
            $table->unsignedBigInteger('warehouse_idx')->nullable()->comment('제품 창고 ( 외주 공정이 마지막 공정일 때 )');
            $table->unsignedBigInteger('inspection_idx')->nullable()->comment('외주 검사');
            $table->unsignedBigInteger('run_idx')->nullable()->comment('외주 공정 실적 ( 합격분 )');
            $table->dateTime('done_at')->nullable()->comment('입고 완료 일시');
            $table->string('remark', 200)->nullable();
            $table->integer('canceled_by')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->index(['subcon_issue_idx', 'status']);
            $table->index('received_at');
            $table->comment('외주 입고');
        });

        DB::statement("ALTER TABLE inspection MODIFY insp_type ENUM('INCOMING','IFL_FIRST','IFL_MIDDLE','IFL_LAST','PROCESS','SHIPPING','SUBCON') NOT NULL COMMENT '검사 종류'");

        Schema::table('wip_txn', function (Blueprint $table) {
            $table->unsignedBigInteger('subcon_issue_idx')->nullable()->after('run_idx')->comment('원인 외주 출고 ( 앞 공정 − )');
            $table->index('subcon_issue_idx');
        });

        // 메뉴 ( 4005 하위 — 400503 은 품질검사 )
        $menus = [
            '4005'   => ['menu_name' => '외주관리'],
            '400501' => ['menu_name' => '외주 출고',     'url_path' => '/subcon/issue',      'page_component' => 'Production/SubconIssue'],
            '400502' => ['menu_name' => '외주 입고',     'url_path' => '/subcon/receipt',    'page_component' => 'Production/SubconReceipt'],
            '400503' => ['menu_name' => '외주 품질검사', 'url_path' => '/subcon/inspection', 'page_component' => 'Production/SubconInspection'],
            '400504' => ['menu_name' => '외주 이력관리', 'url_path' => '/subcon/history',    'page_component' => 'Production/SubconHistory', 'read_only' => 'Y'],
        ];
        foreach ($menus as $code => $set) {
            DB::table('system_menu')->where('menu_code', $code)->update($set);
        }
    }

    public function down(): void {
        foreach (['400501' => '외주 출고', '400502' => '외주 입고', '400503' => '품질검사', '400504' => '외주 이력관리'] as $code => $name) {
            DB::table('system_menu')->where('menu_code', $code)->update(['menu_name' => $name, 'url_path' => null, 'page_component' => null, 'read_only' => 'N']);
        }
        DB::table('system_menu')->where('menu_code', '4005')->update(['menu_name' => '외주관리 ( 미개발 )']);
        Schema::table('wip_txn', function (Blueprint $table) {
            $table->dropIndex(['subcon_issue_idx']);
            $table->dropColumn('subcon_issue_idx');
        });
        DB::statement("ALTER TABLE inspection MODIFY insp_type ENUM('INCOMING','IFL_FIRST','IFL_MIDDLE','IFL_LAST','PROCESS','SHIPPING') NOT NULL COMMENT '검사 종류'");
        Schema::dropIfExists('subcon_receipt');
        Schema::dropIfExists('subcon_issue');
    }
};

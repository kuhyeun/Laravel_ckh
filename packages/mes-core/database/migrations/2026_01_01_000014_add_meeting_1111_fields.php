<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 11-11 회의 수정사항 ( 입력란 추가 )
//   partner        : 주소 · 전화번호 ( 거래처 본체 — 출고지 partner_site 와 별개 · 출고지가 없을 때 문서에 쓴다 )
//   account_member : 사번 · 부서 · 직급 ( 부서 · 직급 = 공통코드 DEPT · POSITION 의 코드키 )
//   sales_order    : 담당자 ( 글로 적는다 )
//   lot            : Heat No ( 공급처 LOT external_lot_no 와 따로 )
//   shipment       : 출하 담당자( 글 ) · 출고지( 거래처 출고지 partner_site ) · 주소 ( 출고지 주소로 채우고 고칠 수 있다 )
return new class extends Migration {

    public function up(): void {
        Schema::table('partner', function (Blueprint $table) {
            $table->string('address', 200)->nullable()->after('ceo_name')->comment('주소');
            $table->string('tel', 30)->nullable()->after('address')->comment('전화번호');
        });
        Schema::table('account_member', function (Blueprint $table) {
            $table->string('emp_no', 30)->nullable()->after('user_name')->comment('사번');
            $table->string('dept_code', 30)->nullable()->after('emp_no')->comment('부서 ( 공통코드 DEPT )');
            $table->string('position_code', 30)->nullable()->after('dept_code')->comment('직급 ( 공통코드 POSITION )');
        });
        Schema::table('sales_order', function (Blueprint $table) {
            $table->string('manager_name', 50)->nullable()->after('partner_idx')->comment('담당자');
        });
        Schema::table('lot', function (Blueprint $table) {
            $table->string('heat_no', 50)->nullable()->after('external_lot_no')->comment('Heat No');
            $table->index('heat_no');
        });
        Schema::table('shipment', function (Blueprint $table) {
            $table->string('manager_name', 50)->nullable()->after('partner_idx')->comment('출하 담당자');
            $table->unsignedBigInteger('site_idx')->nullable()->after('manager_name')->comment('출고지 ( 거래처 출고지 )');
            $table->string('ship_address', 200)->nullable()->after('site_idx')->comment('주소 ( 출고지 주소로 채움 · 고칠 수 있음 )');
            $table->foreign('site_idx')->references('site_idx')->on('partner_site')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('shipment', function (Blueprint $table) {
            $table->dropForeign(['site_idx']);
            $table->dropColumn(['manager_name', 'site_idx', 'ship_address']);
        });
        Schema::table('lot', function (Blueprint $table) {
            $table->dropIndex(['heat_no']);
            $table->dropColumn('heat_no');
        });
        Schema::table('sales_order', function (Blueprint $table) {
            $table->dropColumn('manager_name');
        });
        Schema::table('account_member', function (Blueprint $table) {
            $table->dropColumn(['emp_no', 'dept_code', 'position_code']);
        });
        Schema::table('partner', function (Blueprint $table) {
            $table->dropColumn(['address', 'tel']);
        });
    }
};

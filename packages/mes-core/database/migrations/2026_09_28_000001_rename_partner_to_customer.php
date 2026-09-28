<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 거래처 : partner → customer 로 용어 통일 ( 테이블 · 컬럼 · 코드 그룹 · 메뉴 경로 )
//   데이터는 유지한다 ( 이름만 변경 )
return new class extends Migration {

    // [ 이전 테이블 => 새 테이블 ]
    private const TABLES = [
        'partner'         => 'customer',
        'partner_site'    => 'customer_site',
        'partner_contact' => 'customer_contact',
    ];

    public function up(): void {
        foreach (self::TABLES as $from => $to) {
            Schema::rename($from, $to);
        }

        Schema::table('customer', function (Blueprint $table) {
            $table->renameColumn('partner_idx', 'customer_idx');
            $table->renameColumn('partner_code', 'customer_code');
            $table->renameColumn('partner_name', 'customer_name');
        });
        Schema::table('customer_site', fn (Blueprint $table) => $table->renameColumn('partner_idx', 'customer_idx'));
        Schema::table('customer_contact', fn (Blueprint $table) => $table->renameColumn('partner_idx', 'customer_idx'));

        // 담당자 구분 코드 그룹
        DB::table('system_code_group')->where('system_code_group_key', 'PARTNER_CONTACT_TYPE')
            ->update(['system_code_group_key' => 'CUSTOMER_CONTACT_TYPE']);
        DB::table('system_code')->where('system_code_group_key', 'PARTNER_CONTACT_TYPE')
            ->update(['system_code_group_key' => 'CUSTOMER_CONTACT_TYPE']);

        // 거래처관리 메뉴 경로 · 화면 컴포넌트
        DB::table('system_menu')->where('page_component', 'Config/PartnerManage')
            ->update(['url_path' => '/config/customer', 'page_component' => 'Config/CustomerManage']);
    }

    public function down(): void {
        DB::table('system_menu')->where('page_component', 'Config/CustomerManage')
            ->update(['url_path' => '/config/partner', 'page_component' => 'Config/PartnerManage']);

        DB::table('system_code')->where('system_code_group_key', 'CUSTOMER_CONTACT_TYPE')
            ->update(['system_code_group_key' => 'PARTNER_CONTACT_TYPE']);
        DB::table('system_code_group')->where('system_code_group_key', 'CUSTOMER_CONTACT_TYPE')
            ->update(['system_code_group_key' => 'PARTNER_CONTACT_TYPE']);

        Schema::table('customer_contact', fn (Blueprint $table) => $table->renameColumn('customer_idx', 'partner_idx'));
        Schema::table('customer_site', fn (Blueprint $table) => $table->renameColumn('customer_idx', 'partner_idx'));
        Schema::table('customer', function (Blueprint $table) {
            $table->renameColumn('customer_name', 'partner_name');
            $table->renameColumn('customer_code', 'partner_code');
            $table->renameColumn('customer_idx', 'partner_idx');
        });

        foreach (array_reverse(self::TABLES, true) as $from => $to) {
            Schema::rename($to, $from);
        }
    }
};

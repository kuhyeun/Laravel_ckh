<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 검사기준서를 품목 · 검사 종류에서 떼어 낸다 ( 11-18 )
//   전 : 기준서 = 품목 × 검사 종류( 입고 / 공정 / 출하 )당 사용 중 하나
//   후 : 기준서 = 이름 + 항목 줄만 가진 독립 문서 — 여러 개를 따로 관리한다. 품목 · 검사 종류 · 공정에 묶지 않는다
//        기본 기준서( is_default ) 하나 — 처음 값은 「공정검사」 · 검사 창을 열면 먼저 골라져 있다 ( 다른 기준서로 바꿔 볼 수 있다 )
//        검사 기록( inspection )에 그때 보고 검사한 기준서를 남긴다 ( inspection_spec_idx )
//   기존 기준서 : 그대로 두고 품목 · 검사 종류 값만 비운다
return new class extends Migration {

    public function up(): void {
        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->dropForeign(['item_idx']);
        });
        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->unsignedBigInteger('item_idx')->nullable()->comment('( 쓰지 않음 · 11-18 ) 품목 IDX')->change();
            $table->enum('is_default', ['Y', 'N'])->default('N')->after('insp_type')->comment('기본 기준서 ( 하나 — 검사 창에서 먼저 골라진다 )');
        });
        DB::statement("ALTER TABLE inspection_spec MODIFY insp_type ENUM('INCOMING', 'PROCESS', 'SHIPPING') NULL COMMENT '( 쓰지 않음 · 11-18 ) 검사 종류'");
        DB::table('inspection_spec')->update(['item_idx' => null, 'insp_type' => null]);

        if (!DB::table('inspection_spec')->where('is_default', 'Y')->exists()) {
            $same = DB::table('inspection_spec')->where('inspection_spec_name', '공정검사')->value('inspection_spec_idx');
            if ($same) {
                DB::table('inspection_spec')->where('inspection_spec_idx', $same)->update(['is_default' => 'Y', 'is_use' => 'Y']);
            } else {
                DB::table('inspection_spec')->insert(['inspection_spec_name' => '공정검사', 'is_default' => 'Y', 'is_use' => 'Y', 'remark' => '기본 기준서', 'create_account_idx' => 0]);
            }
        }

        Schema::table('inspection', function (Blueprint $table) {
            $table->unsignedBigInteger('inspection_spec_idx')->nullable()->after('inspector_idx')->comment('보고 검사한 기준서 ( 없으면 NULL )');
        });
    }

    public function down(): void {
        Schema::table('inspection', function (Blueprint $table) {
            $table->dropColumn('inspection_spec_idx');
        });
        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};

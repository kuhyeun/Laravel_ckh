<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 품번 + 규격 = 품목 ( docs/품목-규격구분-설계.md ) — 자재는 품번이 같아도 규격이 다르면 다른 품목
//   item.spec_key : 규격을 비교용으로 정리한 값 ( 공백 · 대소문자 무시 · x / × → X — Item::specKey ) · 모델이 채운다
//   유일 키 item_code → ( item_code, spec_key )
return new class extends Migration {
    public function up(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->string('spec_key', 100)->default('')->after('spec')->comment('규격 비교 값 ( 공백 · 대소문자 무시 · x/× → X ) — 품번 + 이것이 유일');
        });
        foreach (DB::table('item')->get(['item_idx', 'spec']) as $i) {
            DB::table('item')->where('item_idx', $i->item_idx)->update(['spec_key' => \MesCore\Config\Models\Item::specKey($i->spec)]);
        }
        Schema::table('item', function (Blueprint $table) {
            $table->dropUnique(['item_code']);
            $table->unique(['item_code', 'spec_key']);
        });
    }

    public function down(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->dropUnique(['item_code', 'spec_key']);
            $table->unique('item_code');   // 같은 품번이 둘이면 실패한다 — 먼저 정리할 것
            $table->dropColumn('spec_key');
        });
    }
};

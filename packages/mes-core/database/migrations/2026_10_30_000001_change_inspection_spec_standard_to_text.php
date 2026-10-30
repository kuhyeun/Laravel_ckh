<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 검사기준서 규격을 글자로 ( 10-30 ) — "100x300" · "M8 × 1.25" 처럼 숫자가 아닌 규격이 있다
//   하한 · 상한은 숫자 그대로 ( 판정에 쓰는 값 ) / 규격이 숫자면 지금처럼 상하한 범위 안인지 본다
return new class extends Migration {

    public function up(): void {
        Schema::table('inspection_spec_item', function (Blueprint $table) {
            $table->string('standard_value', 100)->nullable()->comment('규격 ( 정량만 · 글자 — 예: 100x300 )')->change();
        });
        // decimal 이 글자로 바뀌며 붙은 뒤쪽 0 정리 ( 10.5000 → 10.5 , 12.0000 → 12 )
        foreach (DB::table('inspection_spec_item')->whereNotNull('standard_value')->get(['inspection_spec_item_idx', 'standard_value']) as $r) {
            if (is_numeric($r->standard_value) && str_contains($r->standard_value, '.')) {
                DB::table('inspection_spec_item')->where('inspection_spec_item_idx', $r->inspection_spec_item_idx)
                    ->update(['standard_value' => rtrim(rtrim($r->standard_value, '0'), '.')]);
            }
        }
    }

    public function down(): void {
        // 숫자가 아닌 규격은 되돌릴 수 없어 비운다
        DB::table('inspection_spec_item')->whereNotNull('standard_value')->get(['inspection_spec_item_idx', 'standard_value'])
            ->filter(fn ($r) => !is_numeric($r->standard_value))
            ->each(fn ($r) => DB::table('inspection_spec_item')->where('inspection_spec_item_idx', $r->inspection_spec_item_idx)->update(['standard_value' => null]));
        Schema::table('inspection_spec_item', function (Blueprint $table) {
            $table->decimal('standard_value', 15, 4)->nullable()->comment('규격 ( 정량만 )')->change();
        });
    }
};

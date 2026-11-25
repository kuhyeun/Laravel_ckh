<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 검사성적서 ( 11-23 · docs/검사성적서-설계.md )
//   1. 기준서 줄 : 시료 수( 1 ~ 10 — 이 수만큼 측정값 칸 )
//   2. 기본 기준서 : 하나( is_default ) → 검사 종류별( 입고 · 공정 · 출하 ) — 기존 기본은 공정 기본으로
//   3. 측정 기록 inspection_measure : 검사 한 건 × 기준서 줄 하나 ( 저장 때 줄 복사본 + 시료별 측정값 + 판정 )
//   4. 설정 : 숨은 quality.mode.*( SIMPLE / SHEET ) → quality.report.*( 성적서 관리 켬 / 끔 ) — SHEET 였으면 켬
return new class extends Migration {

    private const TYPES = ['incoming', 'process', 'shipping'];

    public function up(): void {
        Schema::table('inspection_spec_item', function (Blueprint $table) {
            $table->unsignedTinyInteger('sample_count')->default(1)->after('criteria')->comment('시료 수 ( 1 ~ 10 — 성적서의 측정값 칸 수 )');
        });

        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->enum('default_incoming', ['Y', 'N'])->default('N')->after('inspection_spec_name')->comment('입고검사 기본 기준서 ( 종류마다 하나 )');
            $table->enum('default_process', ['Y', 'N'])->default('N')->after('default_incoming')->comment('공정검사 기본 기준서 ( 종류마다 하나 )');
            $table->enum('default_shipping', ['Y', 'N'])->default('N')->after('default_process')->comment('출하검사 기본 기준서 ( 종류마다 하나 )');
        });
        if (Schema::hasColumn('inspection_spec', 'is_default')) {
            DB::table('inspection_spec')->where('is_default', 'Y')->update(['default_process' => 'Y']);
            Schema::table('inspection_spec', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }

        Schema::create('inspection_measure', function (Blueprint $table) {
            $table->id('inspection_measure_idx')->comment('측정 기록 IDX');
            $table->foreignId('inspection_idx')->comment('검사 IDX')->constrained(table: 'inspection', column: 'inspection_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq')->comment('순서 ( 1부터 )');
            $table->unsignedBigInteger('inspection_spec_item_idx')->nullable()->comment('출처 기준서 줄 ( 참고 — 값은 아래 복사본 )');
            $table->string('inspection_item_name', 60)->comment('항목명 ( 복사 )');
            $table->enum('is_quantitative', ['Y', 'N'])->comment('성격 ( Y 정량 / N 정성 · 복사 )');
            $table->string('method', 60)->nullable()->comment('검사 방법 ( 복사 )');
            $table->string('standard_value', 100)->nullable()->comment('규격 ( 복사 )');
            $table->decimal('lower_limit', 15, 4)->nullable()->comment('하한 ( 복사 )');
            $table->decimal('upper_limit', 15, 4)->nullable()->comment('상한 ( 복사 )');
            $table->string('unit_code', 100)->nullable()->comment('단위 ( 복사 )');
            $table->string('criteria', 500)->nullable()->comment('판정 기준 ( 복사 )');
            $table->unsignedTinyInteger('sample_count')->default(1)->comment('시료 수');
            $table->json('measured')->comment('시료별 측정값 ( 정량 = 숫자 · 정성 = OK / NG )');
            $table->enum('judge', ['OK', 'NG'])->comment('이 줄 판정 ( 시료 하나라도 NG 면 NG — 서버가 매긴다 )');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index(['inspection_idx', 'seq']);
            $table->comment('검사 측정 기록 ( 성적서 — 검사 한 건 × 기준서 줄 )');
        });

        // 설정 : mode.* → report.*
        if (Schema::hasTable('system_config')) {
            foreach (self::TYPES as $t) {
                $row = DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', "mode.{$t}")->first();
                if (!$row) continue;
                DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', "mode.{$t}")->update([
                    'system_config_key'           => "report.{$t}",
                    'system_config_value'         => $row->system_config_value === 'SHEET' ? 'Y' : null,
                    'system_config_value_type'    => 'BOOL',
                    'system_config_default_value' => 'N',
                    'system_config_etc'           => json_encode(['type' => 'switch', 'trueValue' => 'Y', 'falseValue' => 'N']),
                    'system_config_is_visible'    => 'Y',
                ]);
                if (Schema::hasTable('system_config_history')) {
                    DB::table('system_config_history')->where('system_config_group_key', 'quality')->where('system_config_key', "mode.{$t}")->update(['system_config_key' => "report.{$t}"]);
                }
            }
        }
    }

    public function down(): void {
        Schema::dropIfExists('inspection_measure');
        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->enum('is_default', ['Y', 'N'])->default('N')->after('inspection_spec_name')->comment('기본 기준서');
        });
        DB::table('inspection_spec')->where('default_process', 'Y')->update(['is_default' => 'Y']);
        Schema::table('inspection_spec', function (Blueprint $table) {
            $table->dropColumn(['default_incoming', 'default_process', 'default_shipping']);
        });
        Schema::table('inspection_spec_item', function (Blueprint $table) {
            $table->dropColumn('sample_count');
        });
        foreach (self::TYPES as $t) {
            DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', "report.{$t}")->update([
                'system_config_key' => "mode.{$t}", 'system_config_value' => null, 'system_config_value_type' => 'ENUM', 'system_config_default_value' => 'SIMPLE', 'system_config_is_visible' => 'N',
            ]);
        }
    }
};

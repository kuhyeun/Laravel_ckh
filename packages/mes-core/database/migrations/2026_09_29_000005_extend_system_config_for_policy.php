<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 정책 설정 — docs/정책-설정-설계.md 를 system_config 에 합쳐서 구현
//   설계의 policy_def( 카탈로그 ) + policy_value( 업체 값 )를 system_config 한 행에 둔다
//     ( 업체별 DB 구조라 한 DB = 한 업체. 정의는 배포 때 시더가 동기화하고 값은 건드리지 않음 )
//   system_config_override : 거래처 · 품목 · 공정 · 창고별 덮어쓰기 ( 설계의 ENTITY 스코프 )
//   system_config_history  : 변경 이력 ( 소급 판단 근거 )
//   system_menu.feature_key : 메뉴 ↔ 기능 스위치 연결 ( 꺼지면 메뉴 미표시 )
return new class extends Migration {

    public function up(): void {
        Schema::table('system_config', function (Blueprint $table) {
            // NULL = 설정 안 함 → 기본값을 쓴다
            $table->string('system_config_value', 300)->nullable()->default(null)->comment('설정값 ( NULL 이면 기본값 )')->change();

            $table->enum('system_config_category', ['APP', 'FEATURE', 'RULE'])->default('APP')->after('system_config_etc')
                ->comment('구분 ( APP 앱 설정 / FEATURE 기능 스위치 / RULE 업무 규칙 )');
            $table->enum('system_config_value_type', ['BOOL', 'INT', 'ENUM', 'STRING'])->default('STRING')->after('system_config_category')
                ->comment('값 형태 ( BOOL 은 Y/N 으로 저장 )');
            $table->string('system_config_default_value', 300)->nullable()->after('system_config_value_type')->comment('기본값 ( 제품이 정함 )');
            $table->string('system_config_allowed_scopes', 100)->default('TENANT')->after('system_config_default_value')
                ->comment('허용 스코프 ( 예: TENANT,ITEM,PARTNER )');
            $table->enum('system_config_merge_strategy', ['OVERRIDE', 'EXEMPT_THEN_PRIORITY'])->default('OVERRIDE')->after('system_config_allowed_scopes')
                ->comment('스코프 충돌 규칙');
            $table->string('system_config_exempt_value', 30)->nullable()->after('system_config_merge_strategy')->comment('EXEMPT_THEN_PRIORITY : 면제값');
            $table->string('system_config_exempt_scope', 10)->nullable()->after('system_config_exempt_value')->comment('EXEMPT_THEN_PRIORITY : 면제가 최우선인 스코프');
            $table->string('system_config_priority_scope', 10)->nullable()->after('system_config_exempt_scope')->comment('EXEMPT_THEN_PRIORITY : 값이 있으면 이기는 스코프');
            $table->string('system_config_depends_on_key', 60)->nullable()->after('system_config_priority_scope')->comment('선행 정책 키 ( group.key )');
            $table->string('system_config_depends_on_value', 30)->nullable()->after('system_config_depends_on_key')->comment('선행 정책이 이 값이어야 유효 ( NULL 이면 Y )');
            $table->enum('system_config_editable_by', ['SYSTEM_ADMIN', 'TENANT'])->default('TENANT')->after('system_config_depends_on_value')
                ->comment('수정 가능 ( SYSTEM_ADMIN 시스템 계정만 / TENANT 업체 )');
            $table->unsignedSmallInteger('system_config_sort')->default(0)->after('system_config_editable_by')->comment('화면 정렬');
            $table->enum('system_config_is_visible', ['Y', 'N'])->default('Y')->after('system_config_sort')
                ->comment('화면 노출 ( N = 내부 튜닝값 또는 아직 쓰는 곳 없음 )');
        });

        Schema::create('system_config_override', function (Blueprint $table) {
            $table->id('system_config_override_idx')->comment('덮어쓰기 IDX');
            $table->string('system_config_group_key', 50)->comment('설정 GROUP KEY');
            $table->string('system_config_key', 50)->comment('설정 KEY');
            $table->enum('scope_type', ['PARTNER', 'ITEM', 'PROCESS', 'WAREHOUSE'])->comment('대상 종류');
            $table->unsignedBigInteger('scope_idx')->comment('대상 IDX ( partner_idx · item_idx … )');
            $table->string('override_value', 300)->comment('덮어쓴 값');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['system_config_group_key', 'system_config_key', 'scope_type', 'scope_idx'], 'uk_config_override');
            $table->index(['scope_type', 'scope_idx']);
            $table->foreign(['system_config_group_key', 'system_config_key'], 'fk_config_override_def')
                ->references(['system_config_group_key', 'system_config_key'])->on('system_config')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->comment('설정 덮어쓰기 ( 거래처 · 품목 · 공정 · 창고별 )');
        });

        Schema::create('system_config_history', function (Blueprint $table) {
            $table->id('system_config_history_idx')->comment('이력 IDX');
            $table->string('system_config_group_key', 50)->comment('설정 GROUP KEY');
            $table->string('system_config_key', 50)->comment('설정 KEY');
            $table->string('scope_type', 10)->default('TENANT')->comment('TENANT 또는 대상 종류');
            $table->unsignedBigInteger('scope_idx')->default(0)->comment('대상 IDX ( TENANT 면 0 )');
            $table->string('old_value', 300)->nullable()->comment('이전 값 ( NULL = 기본값 / 덮어쓰기 없음 )');
            $table->string('new_value', 300)->nullable()->comment('새 값 ( NULL = 기본값으로 되돌림 / 덮어쓰기 해제 )');
            $table->string('reason', 300)->nullable()->comment('변경 사유');
            $table->integer('create_account_idx')->comment('변경자');
            $table->timestamp('create_datetime')->useCurrent()->comment('변경일');

            $table->index(['system_config_group_key', 'system_config_key', 'create_datetime'], 'ix_config_history_key');
            $table->comment('설정 변경 이력');
        });

        Schema::table('system_menu', function (Blueprint $table) {
            $table->string('feature_key', 60)->nullable()->after('module_code')
                ->comment('연결된 기능 스위치 ( feature.lot 등. 꺼지면 메뉴 미표시 )');
        });
    }

    public function down(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->dropColumn('feature_key');
        });
        Schema::dropIfExists('system_config_history');
        Schema::dropIfExists('system_config_override');
        Schema::table('system_config', function (Blueprint $table) {
            $table->dropColumn([
                'system_config_category', 'system_config_value_type', 'system_config_default_value',
                'system_config_allowed_scopes', 'system_config_merge_strategy', 'system_config_exempt_value',
                'system_config_exempt_scope', 'system_config_priority_scope', 'system_config_depends_on_key',
                'system_config_depends_on_value', 'system_config_editable_by', 'system_config_sort', 'system_config_is_visible',
            ]);
        });
    }
};

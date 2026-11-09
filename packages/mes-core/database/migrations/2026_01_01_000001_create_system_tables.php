<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 시스템 · 계정 · 메뉴 · 권한 · 설정 · 코드 · 공용
//   현재 구조를 첫 기준으로 다시 씀 ( 2026-11-09 — 개발 중 쌓인 마이그레이션 77개를 정리 )
//   cache                       : 
//   cache_locks                 : 
//   system_menu                 : 메뉴 정보
//   system_menu_option          : 메뉴설정 ( 권한 및 순서 )
//   permission_template         : 권한 템플릿 ( 사용자에게 연결 → 템플릿 수정 시 연결된 사용자 전원 반영 )
//   permission_template_menu    : 템플릿별 메뉴 권한 ( 행이 없는 메뉴 = 권한 없음 )
//   system_config               : 시스템 환경설정
//   system_config_history       : 설정 변경 이력
//   system_config_override      : 설정 덮어쓰기 ( 거래처 · 품목 · 공정 · 창고별 )
//   system_code_group           : 시스템 코드 그룹
//   system_code                 : 시스템 코드
//   user_grid_setting           : 사용자별 그리드 열 설정 ( 표시 · 이름 · 순서 · 너비 )
//   attachment                  : 첨부파일
//   doc_seq                     : 문서 번호 순번
//   account_member              : 사용자 테이블
//   account_menu_permission     : 사용자별 메뉴 권한 ( 레벨 기본값 override )
//
// ── 처음 만들 때의 설계 메모 ──
// 정책 설정 — docs/정책-설정-설계.md 를 system_config 에 합쳐서 구현
//   설계의 policy_def( 카탈로그 ) + policy_value( 업체 값 )를 system_config 한 행에 둔다
//     ( 업체별 DB 구조라 한 DB = 한 업체. 정의는 배포 때 시더가 동기화하고 값은 건드리지 않음 )
//   system_config_override : 거래처 · 품목 · 공정 · 창고별 덮어쓰기 ( 설계의 ENTITY 스코프 )
//   system_config_history  : 변경 이력 ( 소급 판단 근거 )
//   system_menu.feature_key : 메뉴 ↔ 기능 스위치 연결 ( 꺼지면 메뉴 미표시 )
//
// 그리드 열 설정 ( 10-29 ) — 사용자 × 화면( page_component ) × 그리드 키 마다 하나
//   columns JSON = [{ name, header?, hidden?, width? }] — 순서가 곧 열 순서. 화면 코드에 새로 생긴 열은 끝에 보이게 붙는다
//   관리자 기본 설정( 회사 기본 열 구성 )은 아직 없음 — 필요해지면 account_idx 0 행으로 붙일 수 있다
//
// 첨부파일 ( CMP-03 ) — ERD "attachment.ref_type + ref_id 로 어떤 테이블에도 붙는다"
//   파일은 Storage 에 두고 여기에는 위치만 ( DB 에 파일을 넣지 않는다 — 프로그램-스펙 §10 )
//   저장 경로 : attachments/{DB명}/{yyyyMM}/{uuid}.{확장자} ( 멀티테넌시 대비 — 업체 구분 폴더 )
//   삭제는 행 · 파일 모두 지운다 ( 사용자 판단 2026-10-08 )
//
// 문서 번호 순번 ( Support/DocNumber — 코드 검사 B-4 ) : 머리( 'SO-261109-' )마다 마지막 순번
//   업무 데이터 초기화( mes:reset-transactions )가 같이 비운다
return new class extends Migration {

    public function up(): void {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 255);
            $table->mediumText('value');
            $table->integer('expiration');

            $table->primary('key');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 255);
            $table->string('owner', 255);
            $table->integer('expiration');

            $table->primary('key');
        });

        Schema::create('system_menu', function (Blueprint $table) {
            $table->id('menu_idx')->comment('메뉴 IDX');
            $table->string('menu_code', 30)->comment('메뉴 코드');
            $table->string('top_menu_code', 30)->default('****')->comment('최상위 메뉴 코드');
            $table->string('parent_menu_code', 30)->nullable()->comment('부모 메뉴 코드');
            $table->string('menu_name', 50)->comment('메뉴 이름');
            $table->string('menu_icon', 50)->nullable()->comment('메뉴 ICON');
            $table->integer('menu_depth')->default(1)->comment('메뉴 깊이');
            $table->string('module_code', 50)->comment('메뉴 모듈코드');
            $table->string('feature_key', 60)->nullable()->comment('연결된 기능 스위치 ( feature.lot 등. 꺼지면 메뉴 미표시 )');
            $table->string('menu_route_name', 50)->nullable()->comment('메뉴 라우팅');
            $table->string('url_path', 150)->nullable()->comment('접속 경로 ( 제네릭 라우팅 )');
            $table->string('page_component', 150)->nullable()->comment('렌더할 Vue 컴포넌트 경로');
            $table->string('page_options', 500)->nullable()->comment('화면 옵션 JSON ( 메뉴마다 다르게 — 화면이 읽는다 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->enum('is_display', ['Y', 'N'])->default('Y')->comment('메뉴표시 여부');
            $table->enum('is_admin', ['Y', 'N'])->default('N')->comment('관리자 메뉴 여부');
            $table->enum('read_only', ['Y', 'N'])->default('N')->comment('조회 전용 ( Y = 쓰기 · 삭제 권한 항상 없음 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();
            $table->text('remark')->nullable()->comment('비고');

            $table->unique('menu_code');
            $table->index('page_component');
            $table->index('parent_menu_code');
            $table->unique('url_path');
            $table->comment('메뉴 정보');
        });

        Schema::create('system_menu_option', function (Blueprint $table) {
            $table->id('menu_option_idx')->comment('메뉴 옵션 IDX');
            $table->unsignedBigInteger('menu_idx')->comment('메뉴 IDX');
            $table->integer('menu_level')->comment('메뉴 권한레벨');
            $table->enum('can_read', ['Y', 'N'])->default('Y')->comment('노출(읽기) 권한 ( N: 메뉴 숨김 )');
            $table->enum('can_write', ['Y', 'N'])->default('N')->comment('등록·수정 권한');
            $table->enum('can_delete', ['Y', 'N'])->default('N')->comment('삭제 권한');
            $table->integer('menu_sort')->comment('메뉴 정렬순서');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->comment('수정일');

            $table->unique(['menu_idx', 'menu_level']);
            $table->foreign('menu_idx')->references('menu_idx')->on('system_menu')->cascadeOnDelete();
            $table->comment('메뉴설정 ( 권한 및 순서 )');
        });

        Schema::create('permission_template', function (Blueprint $table) {
            $table->id('template_idx')->comment('권한 템플릿 IDX');
            $table->string('template_name', 50)->comment('템플릿명 ( ex. 생산팀, 품질팀 )');
            $table->string('template_desc', 200)->nullable()->comment('템플릿 설명');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('권한 템플릿 ( 사용자에게 연결 → 템플릿 수정 시 연결된 사용자 전원 반영 )');
        });

        Schema::create('permission_template_menu', function (Blueprint $table) {
            $table->id('template_menu_idx')->comment('템플릿 메뉴 권한 IDX');
            $table->unsignedBigInteger('template_idx')->comment('권한 템플릿 IDX');
            $table->foreignId('menu_idx')->comment('메뉴 IDX')->constrained(table: 'system_menu', column: 'menu_idx')->cascadeOnDelete();
            $table->enum('can_read', ['Y', 'N'])->default('N')->comment('노출(읽기) 권한 ( N: 메뉴 숨김 )');
            $table->enum('can_write', ['Y', 'N'])->default('N')->comment('등록·수정 권한');
            $table->enum('can_delete', ['Y', 'N'])->default('N')->comment('삭제 권한');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['template_idx', 'menu_idx']);
            $table->foreign('template_idx')->references('template_idx')->on('permission_template')->cascadeOnDelete();
            $table->comment('템플릿별 메뉴 권한 ( 행이 없는 메뉴 = 권한 없음 )');
        });

        Schema::create('system_config', function (Blueprint $table) {
            $table->string('system_config_group_key', 50)->comment('시스템 설정 GROUP KEY');
            $table->string('system_config_key', 50)->comment('시스템 설정 KEY');
            $table->string('system_config_value', 300)->nullable()->comment('설정값 ( NULL 이면 기본값 )');
            $table->string('system_config_name', 100)->nullable()->default('')->comment('시스템설정 이름');
            $table->string('system_config_remark', 500)->nullable()->comment('시스템 설정 설명');
            $table->text('system_config_etc')->nullable()->comment('시스템 설정 부가 데이터 ( select 후보 등 구조가 필요한 값 )');
            $table->enum('system_config_category', ['APP', 'FEATURE', 'RULE'])->default('APP')->comment('구분 ( APP 앱 설정 / FEATURE 기능 스위치 / RULE 업무 규칙 )');
            $table->enum('system_config_value_type', ['BOOL', 'INT', 'ENUM', 'STRING'])->default('STRING')->comment('값 형태 ( BOOL 은 Y/N 으로 저장 )');
            $table->string('system_config_default_value', 300)->nullable()->comment('기본값 ( 제품이 정함 )');
            $table->string('system_config_allowed_scopes', 100)->default('TENANT')->comment('허용 스코프 ( 예: TENANT,ITEM,PARTNER )');
            $table->enum('system_config_merge_strategy', ['OVERRIDE', 'EXEMPT_THEN_PRIORITY'])->default('OVERRIDE')->comment('스코프 충돌 규칙');
            $table->string('system_config_exempt_value', 30)->nullable()->comment('EXEMPT_THEN_PRIORITY : 면제값');
            $table->string('system_config_exempt_scope', 10)->nullable()->comment('EXEMPT_THEN_PRIORITY : 면제가 최우선인 스코프');
            $table->string('system_config_priority_scope', 10)->nullable()->comment('EXEMPT_THEN_PRIORITY : 값이 있으면 이기는 스코프');
            $table->string('system_config_depends_on_key', 60)->nullable()->comment('선행 정책 키 ( group.key )');
            $table->string('system_config_depends_on_value', 30)->nullable()->comment('선행 정책이 이 값이어야 유효 ( NULL 이면 Y )');
            $table->enum('system_config_editable_by', ['SYSTEM_ADMIN', 'TENANT'])->default('TENANT')->comment('수정 가능 ( SYSTEM_ADMIN 시스템 계정만 / TENANT 업체 )');
            $table->unsignedSmallInteger('system_config_sort')->default(0)->comment('화면 정렬');
            $table->enum('system_config_is_visible', ['Y', 'N'])->default('Y')->comment('화면 노출 ( N = 내부 튜닝값 또는 아직 쓰는 곳 없음 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->primary(['system_config_group_key', 'system_config_key']);
            $table->comment('시스템 환경설정');
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

            $table->index(['scope_type', 'scope_idx']);
            $table->unique(['system_config_group_key', 'system_config_key', 'scope_type', 'scope_idx'], 'uk_config_override');
            $table->foreign(['system_config_group_key', 'system_config_key'], 'fk_config_override_def')->references(['system_config_group_key', 'system_config_key'])->on('system_config')->restrictOnDelete()->cascadeOnUpdate();
            $table->comment('설정 덮어쓰기 ( 거래처 · 품목 · 공정 · 창고별 )');
        });

        Schema::create('system_code_group', function (Blueprint $table) {
            $table->id('system_code_group_idx')->comment('시스템 코드 그룹 IDX');
            $table->string('system_code_group_key', 100)->default('')->comment('시스템 코드 그룹키');
            $table->string('system_code_group_name', 200)->default('')->comment('시스템 코드 그룹명');
            $table->string('system_code_group_desc', 200)->nullable()->comment('시스템 코드 그룹 설명');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('system_code_group_key');
            $table->comment('시스템 코드 그룹');
        });

        Schema::create('system_code', function (Blueprint $table) {
            $table->id('system_code_idx')->comment('시스템 코드 IDX');
            $table->string('system_code_group_key', 100)->default('')->comment('시스템 코드 그룹키');
            $table->string('system_code_key', 100)->comment('시스템 코드키');
            $table->string('system_code_name', 200)->default('')->comment('시스템 코드명');
            $table->string('system_code_value', 200)->nullable()->default('')->comment('시스템 코드값');
            $table->string('system_code_desc', 200)->nullable()->comment('시스템 코드 설명');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( N: 신규 선택 목록에서 제외 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('정렬 순서 ( 오름차순, 같으면 코드키 순 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['system_code_group_key', 'system_code_key'], 'uq_group_code_key');
            $table->comment('시스템 코드');
        });

        Schema::create('user_grid_setting', function (Blueprint $table) {
            $table->id('user_grid_setting_idx')->comment('IDX');
            $table->integer('account_idx')->comment('사용자');
            $table->string('page_component', 100)->comment('화면 ( Inertia page_component )');
            $table->string('grid_key', 50)->default('main')->comment('그리드 키 ( 한 화면에 그리드가 여럿이면 구분 )');
            $table->json('columns')->comment('열 설정 [{ name, header, hidden, width }] — 순서 = 열 순서');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['account_idx', 'page_component', 'grid_key'], 'uq_user_grid_setting');
            $table->comment('사용자별 그리드 열 설정 ( 표시 · 이름 · 순서 · 너비 )');
        });

        Schema::create('attachment', function (Blueprint $table) {
            $table->id('attachment_idx')->comment('첨부 IDX');
            $table->string('ref_type', 30)->comment('붙은 대상 종류 ( 예: item )');
            $table->unsignedBigInteger('ref_idx')->comment('붙은 대상 IDX');
            $table->string('category_code', 30)->comment('구분 ( 코드관리 ATTACH_TYPE — 성적서 · 밀시트 · 도면 … )');
            $table->string('file_name', 255)->comment('원래 파일 이름');
            $table->string('file_ext', 10)->comment('확장자 ( 소문자 )');
            $table->unsignedBigInteger('file_size')->comment('크기 ( byte )');
            $table->string('mime_type', 120)->nullable()->comment('MIME');
            $table->string('storage_disk', 30)->comment('저장 디스크');
            $table->string('storage_path', 300)->comment('저장 경로');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['ref_type', 'ref_idx']);
            $table->comment('첨부파일');
        });

        Schema::create('doc_seq', function (Blueprint $table) {
            $table->string('seq_key', 40)->comment('번호 머리 ( SO-261109- )');
            $table->unsignedInteger('last_seq')->default(0)->comment('마지막 순번');

            $table->primary('seq_key');
            $table->comment('문서 번호 순번');
        });

        Schema::create('account_member', function (Blueprint $table) {
            $table->id('account_idx')->comment('사용자 IDX');
            $table->string('user_id', 50)->comment('사용자 ID');
            $table->string('user_pw', 300)->comment('사용자 PW ( hashed )');
            $table->string('user_name', 50)->default('')->comment('사용자 이름');
            $table->enum('user_type', ['admin', 'user'])->default('user')->comment('사용자 종류');
            $table->integer('user_level')->default(99)->comment('0: 시스템 관리자, 1: 관리자, 10: 중간관리자, 99: 사용자');
            $table->foreignId('permission_template_idx')->nullable()->comment('권한 템플릿 IDX')->constrained(table: 'permission_template', column: 'template_idx')->nullOnDelete();
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->default(0)->comment('생성자');
            $table->timestamp('create_datetime')->useCurrent()->comment('생성일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('user_id');
            $table->comment('사용자 테이블');
        });

        Schema::create('account_menu_permission', function (Blueprint $table) {
            $table->id('permission_idx')->comment('권한 IDX');
            $table->unsignedBigInteger('account_idx')->comment('사용자 IDX');
            $table->foreignId('menu_idx')->comment('메뉴 IDX')->constrained(table: 'system_menu', column: 'menu_idx')->cascadeOnDelete();
            $table->enum('can_read', ['Y', 'N'])->default('N')->comment('읽기 권한 ( N: 메뉴 숨김 )');
            $table->enum('can_write', ['Y', 'N'])->default('N')->comment('쓰기 권한');
            $table->enum('can_delete', ['Y', 'N'])->default('N')->comment('삭제 권한');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['account_idx', 'menu_idx']);
            $table->foreign('account_idx')->references('account_idx')->on('account_member')->cascadeOnDelete();
            $table->comment('사용자별 메뉴 권한 ( 레벨 기본값 override )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('account_menu_permission');
        Schema::dropIfExists('account_member');
        Schema::dropIfExists('doc_seq');
        Schema::dropIfExists('attachment');
        Schema::dropIfExists('user_grid_setting');
        Schema::dropIfExists('system_code');
        Schema::dropIfExists('system_code_group');
        Schema::dropIfExists('system_config_override');
        Schema::dropIfExists('system_config_history');
        Schema::dropIfExists('system_config');
        Schema::dropIfExists('permission_template_menu');
        Schema::dropIfExists('permission_template');
        Schema::dropIfExists('system_menu_option');
        Schema::dropIfExists('system_menu');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};

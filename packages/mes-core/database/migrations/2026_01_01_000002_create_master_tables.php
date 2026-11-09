<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 기준정보 ( 품목 · 거래처 · 공정 · 설비 · 창고 · 라우팅 · BOM · 검사 · 리드타임 · LOT 규칙 · 계산식 )
//   현재 구조를 첫 기준으로 다시 씀 ( 2026-11-09 — 개발 중 쌓인 마이그레이션 77개를 정리 )
//   calc_def                    : 자재소요 계산식
//   warehouse                   : 창고 ( 다단계 로케이션 )
//   process                     : 공정
//   equipment                   : 설비
//   process_equipment           : 공정별 사용 가능 설비
//   item                        : 품목 마스터
//   partner                     : 거래처
//   partner_site                : 거래처 출고지
//   routing                     : 공정 라우팅
//   routing_line                : 라우팅 공정 줄
//   routing_change_log          : 공정 라우팅 변경 이력
//   bom                         : BOM ( 품목 × 버전 )
//   bom_line                    : BOM 자재 줄
//   bom_line_substitute         : BOM 대체 자재
//   bom_change_log              : 수주 자재 변경 이력
//   inspection_item             : 검사항목
//   inspection_spec             : 검사기준서
//   inspection_spec_item        : 검사기준서 줄
//   lead_time                   : 제조리드타임
//   lot_rule                    : LOT 규칙
//   partner_contact             : 거래처 담당자
//
// ── 처음 만들 때의 설계 메모 ──
// 자재출고 ( INV-03 · 생산 2단계 ) — docs/screens/50-생산.md §10 ~ §17
//   calc_def  : 자재소요 계산식 ( 자재소요-계산-설계 §7 ). 설계는 공용 DB 지만 업체별 설치( ADR 0027 )라 업체 DB 에. 내장 식은 CalcDefSeeder 가 매번 덮어쓴다
//   wo_stock  : 지시가 보유한 자재 ( 재고-수불 §9.2-1 ). 출고할 때만 원장 OUT — 사용처리는 이 표만
//   stock_txn_line : 출고 줄이 어느 지시 · 자재 줄의 것인지 + 출고와 함께 사용처리한 수량( consume_timing = ISSUE )
//   work_order_material.issued_qty : 줄별 출고 합계 ( 표시용 — 전표에서 다시 계산할 수 있다 )
//
// 창고 ( BAS-06 · ERD §6 )
//   자기참조 계층 ( 자재창고 > 1구역 ). 재고는 잎 노드( 하위가 없는 창고 )에 붙는다
//
// 설비 ( BAS-07 ) · 공정 ( BAS-04 ) — docs/screens/20-기준정보.md · ERD §6
//   equipment         : 설비 마스터 ( 등록만, 보전·유휴는 Phase 3 )
//   process           : 공정 마스터 ( 시스템 기본 공정 4종은 is_system = Y 로 표시 )
//   process_equipment : 공정별 사용 가능 설비 N ( 기본 설비 하나 · 물림·세팅 손실 )
//
// 품목 마스터 ( docs/screens/20-기준정보.md BAS-02 · ERD §6 )
//   Phase 1 1차: 기본 · 재고/발주 · 단가 탭의 컬럼
//   라우팅 · BOM · 리드타임 · 정책 오버라이드 · 커스텀 컬럼은 해당 테이블이 생길 때 추가
//
// 거래처 ( docs/screens/20-기준정보.md BAS-01 · ERD §6 )
//   partner         : 거래처 기본 ( 구매처 · 고객사 · 외주처 멀티 구분 )
//   partner_site    : 출고지 N
//   partner_contact : 담당자 N ( 구분 = 코드 PARTNER_CONTACT_TYPE / 소속 = 출고지, 비우면 본사 )
//   입고검사 설정( 정책 ENTITY 오버라이드 )은 정책 테이블 생성 시 추가
//
// 공정라우팅 ( BAS-05 · ERD §6 )
//   routing      : 라우팅 머리 ( 기본 라우팅은 하나 — 품목 등록 시점에만 자동 적용 )
//   routing_line : 공정 순서. 같은 공정 중복 허용
//     BOM 의 투입 지점이 이 줄의 IDX 를 가리키므로( 순번이 아님 ) 순서를 바꿔도 참조가 유지된다
//
// 공정 라우팅 3단계 ( docs/라우팅-3단계-설계.md )
//   routing.owner_type : TEMPLATE( ① 공용 틀 ) · ITEM( ② 품목 전용 ) · ORDER_LINE( ③ 수주 줄 전용 )
//   routing_line.source_routing_line_idx : 복사해 온 원본 줄 ( BOM 투입 지점을 ② → ③ → 작업지시로 잇는다 )
//   routing_change_log : 품목 · 수주 · 작업지시 공정 변경 이력
//   이관 : 라우팅을 쓰는 제품 품목마다 틀을 ② 로 복사 → BOM 투입 지점 · 작업지시 공정 참조 · item.routing_idx 를 ② 로
//     ( 작업지시까지 옮기지 않으면 진행 중인 지시를 다시 저장할 때 자재 투입 지점이 끊긴다 )
//   ③ 이관은 2단계( 수주 ) 마이그레이션에서
//
// BOM ( BAS-03 · ERD §6 )
//   bom      : 품목 × 버전. 활성은 품목당 하나. 날짜가 셋( 변경일 · 승인일 · 활성 전환일 )
//   bom_line : 자재 줄. 투입 지점은 라우팅의 "줄"을 가리킨다( 공정 마스터가 아님 )
//
// 대체 자재 ( docs/대체자재-수주자재-설계.md §4.2 ) — BOM 자재 줄마다 대신 쓸 수 있는 자재 N
//   소요는 대체 자재마다 따로 ( 소요 · 계산식 · 기능형 값 — 주 자재 줄과 같은 칸 )
//   자재 출고 때 주 자재 대신 · 섞어서 낼 수 있다 ( 자동 채움은 주 자재만 · 부족 판단은 주 자재 기준 )
//
// 수주 자재 ( docs/대체자재-수주자재-설계.md §4 ) — 수주 줄마다 품목 BOM 을 복사해 그 수주에 맞게 고친다
//   bom.owner_type : ITEM( 품목 BOM — BOM관리 ) · ORDER_LINE( 수주 줄 자재 — 목록에 안 보임 · is_active N )
//   bom_line.source_bom_line_idx : 복사해 온 원본 줄 ( 이력 · 비교 )
//   수주 줄 자재의 투입 지점 = 그 수주 줄의 수주 공정( 라우팅 ③ ) 줄 — 품목 BOM 의 투입 지점( 품목 공정 줄 )을 원본으로 둔 줄로 바꾼다
//   bom_change_log : 수주 자재 변경 이력
//   이관 : 기존 수주 줄마다 수주 자재를 만든다
//
// 검사항목 ( BAS-11 ) — docs/screens/20-기준정보.md · ERD "검사 항목은 골라 쓰거나 새로 쓴다"
//   inspection_item : 검사 항목 마스터. 검사기준서( BAS-08 )가 골라 쓴다 ( 고르는 순간 복사 )
//   정량 항목에 규격·상하한 칸은 두지 않는다 ( 품목마다 다르므로 기준서에서 채움 )
//
// 검사기준서 ( BAS-08 ) — docs/screens/20-기준정보.md · ERD "검사 항목은 골라 쓰거나 새로 쓴다"
//   inspection_spec      : 기준서 머리 ( 품목 × 검사 종류, 사용 중인 것은 하나 )
//   inspection_spec_item : 기준서의 줄. 마스터에서 골랐으면 inspection_item_idx 가 채워진다 ( 표시일 뿐 )
//                          고르는 순간 복사 → 마스터를 고쳐도 기존 기준서는 그대로
//   Phase 1 은 SIMPLE 모드라 참고 자료로만 쓴다 ( 값을 채워 판정하는 SHEET 는 Phase 2 )
//
// 제조리드타임 ( BAS-09 ) — docs/screens/20-기준정보.md · ERD "리드타임은 두 축이다"
//   lead_time : 5구간 소요 일수 ( 수량과 무관 ). 개당 시간( routing_line.cycle_time_sec )과는 다른 축
//   기본값은 item_idx = NULL 인 행 하나 ( 문서의 item_id = 0 관례 대신 — FK 를 유지하기 위해 )
//     기본값이 하나뿐인 것은 저장 로직이 보장한다 ( MySQL UNIQUE 는 NULL 중복을 막지 않음 )
//   품목 행이 있으면 그 행만 쓰고 빈 칸은 0( 해당 없음 ), 품목 행이 없으면 기본값
//   실적으로 재계산하는 기능은 Phase 2 — 여기서는 등록만
//
// LOT 규칙 ( BAS-10 ) — docs/screens/20-기준정보.md · ERD "LOT 번호는 조합으로 만든다"
//   lot_rule : 규칙 정의만 담는다. 실제 채번( 순번 카운터 · 발행 )은 작업지시 · 입고 · 출하 개발 때 붙인다
//   format_spec : 요소의 순서 있는 목록 ( JSON ) — [{ type: ITEM_CD }, { type: DATE, unit: YYMM }, { type: SEQ, digits: 3 } …]
//     날짜 세밀도가 곧 순번 리셋 주기. 시리얼은 LOT 단위로 리셋
//   업체가 N개 등록해 두고 발행할 때 고른다 ( 생산 LOT 은 작업지시, 출하 LOT 은 출하등록, 시리얼은 실적에서 자동 )
return new class extends Migration {

    public function up(): void {
        Schema::create('calc_def', function (Blueprint $table) {
            $table->id('calc_def_idx')->comment('계산식 IDX');
            $table->string('calc_code', 30)->comment('계산식 코드 ( item · bom_line 의 calc_code 가 가리킴 )');
            $table->string('name', 60)->comment('이름');
            $table->string('expr', 500)->comment('식 ( 좁은 수식 — 변수 · + - * / ( ) · 함수 6 )');
            $table->enum('result_kind', ['QTY', 'SPEC_COUNT', 'WEIGHT'])->comment('결과 종류 ( QTY · SPEC_COUNT 올림 정수 / WEIGHT 소수 3자리 )');
            $table->string('sample_json', 500)->nullable()->comment('시험 계산에 쓴 값 ( 재검증용 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->enum('is_builtin', ['Y', 'N'])->default('N')->comment('내장 ( 수정 · 삭제 불가 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->default(0)->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('calc_code');
            $table->comment('자재소요 계산식');
        });

        Schema::create('warehouse', function (Blueprint $table) {
            $table->id('warehouse_idx')->comment('창고 IDX');
            $table->string('warehouse_code', 30)->comment('창고코드 ( 유일 )');
            $table->string('warehouse_name', 60)->comment('창고명');
            $table->unsignedBigInteger('parent_idx')->nullable()->comment('상위 창고 IDX ( NULL = 최상위 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('같은 상위 안에서의 표시 순서');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['parent_idx', 'sort_order']);
            $table->unique('warehouse_code');
            $table->foreign('parent_idx')->references('warehouse_idx')->on('warehouse')->restrictOnDelete();
            $table->comment('창고 ( 다단계 로케이션 )');
        });

        Schema::create('process', function (Blueprint $table) {
            $table->id('process_idx')->comment('공정 IDX');
            $table->string('process_code', 30)->comment('공정코드 ( 유일 )');
            $table->string('process_name', 60)->comment('공정명');
            $table->enum('process_type', ['PRODUCTION', 'INSPECTION', 'EQUIPMENT', 'SUBCON'])->comment('공정 구분 ( PRODUCTION 생산 / INSPECTION 검사 / EQUIPMENT 설비생산 - 설비 필수 / SUBCON 외주 )');
            $table->string('class_code', 3)->nullable()->comment('구분 코드 ( 라우트시트 인쇄, 영문 대문자 1~3자 )');
            $table->enum('inspection_kind', ['NORMAL', 'IFL'])->nullable()->comment('검사 종류 ( 검사 공정만. NORMAL 일반 / IFL 초중종 )');
            $table->enum('is_system', ['Y', 'N'])->default('N')->comment('시스템 기본 공정 여부');
            $table->string('system_key', 30)->nullable()->comment('시스템 공정 식별키 ( RM_IN / RM_OUT / INSPECT / PACK )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('process_code');
            $table->index(['process_type', 'is_use']);
            $table->unique('system_key');
            $table->comment('공정');
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id('equipment_idx')->comment('설비 IDX');
            $table->string('equipment_code', 30)->comment('설비코드 ( 유일 )');
            $table->string('equipment_name', 60)->comment('설비명');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('equipment_code');
            $table->index('is_use');
            $table->comment('설비');
        });

        Schema::create('process_equipment', function (Blueprint $table) {
            $table->id('process_equipment_idx')->comment('공정 설비 IDX');
            $table->unsignedBigInteger('process_idx')->comment('공정 IDX');
            $table->foreignId('equipment_idx')->comment('설비 IDX')->constrained(table: 'equipment', column: 'equipment_idx')->restrictOnDelete();
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('기본 설비 여부 ( 공정당 하나 )');
            $table->decimal('setup_loss', 15, 3)->default(0)->comment('물림·세팅 손실 ( 품목 단위 기준, 자르지 않는 공정은 0 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['process_idx', 'equipment_idx']);
            $table->foreign('process_idx')->references('process_idx')->on('process')->cascadeOnDelete();
            $table->comment('공정별 사용 가능 설비');
        });

        Schema::create('item', function (Blueprint $table) {
            $table->id('item_idx')->comment('품목 IDX');
            $table->string('item_code', 40)->comment('품번 ( 유일 )');
            $table->string('item_name', 100)->comment('품명');
            $table->enum('item_type', ['MATERIAL', 'PRODUCT'])->comment('대분류 ( MATERIAL 자재 / PRODUCT 제품 )');
            $table->enum('item_kind', ['RAW', 'STOCK', 'FINISHED', 'SEMI', 'SET'])->comment('소분류 ( 자재: RAW 원자재·STOCK 원소재 / 제품: FINISHED 완제품·SEMI 반제품·SET 세트 )');
            $table->string('spec_unit', 100)->default('EA')->comment('단위 ( system_code UNIT 그룹 코드키 )');
            $table->string('spec', 100)->nullable()->comment('규격');
            $table->string('spec_key', 100)->default('')->comment('규격 비교 값 ( 공백 · 대소문자 무시 · x/× → X ) — 품번 + 이것이 유일');
            $table->string('material', 100)->nullable()->comment('재질 ( 글자 — 예: SUS304 )');
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('기본 라우팅 IDX ( routing 테이블 생성 시 FK 연결 )');
            $table->enum('serial_use', ['Y', 'N'])->default('N')->comment('시리얼 사용 ( 낱개마다 번호 )');
            $table->decimal('pack_qty', 15, 3)->nullable()->comment('포장 수량 ( 박스당 )');
            $table->decimal('safety_stock', 15, 3)->default(0)->comment('안전재고 ( 품목 단위 )');
            $table->unsignedSmallInteger('order_cycle_days')->default(0)->comment('발주 주기 ( 일 )');
            $table->decimal('moq', 15, 3)->default(0)->comment('최소 수량');
            $table->decimal('unit_round', 15, 3)->default(0)->comment('배수 ( 이 배수로 올림 )');
            $table->unsignedTinyInteger('spec_dim')->default(1)->comment('규격 차원 ( Phase 1 은 1 만 )');
            $table->decimal('min_usable_spec', 15, 3)->nullable()->comment('최소 사용 규격 ( 미만 잔재는 폐기 대상, 원소재만 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 코드');
            $table->decimal('cycle_time_sec', 10, 2)->default(0)->comment('1개 생산 표준시간 ( 초, 라우팅 합계 )');
            $table->decimal('std_sale_price', 15, 2)->default(0)->comment('판매단가');
            $table->decimal('std_buy_price', 15, 2)->default(0)->comment('발주단가');
            $table->decimal('std_subcon_price', 15, 2)->default(0)->comment('외주단가');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->string('ext_c01', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c02', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c03', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c04', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c05', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n01', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n02', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n03', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d01')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d02')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->unique(['item_code', 'spec_key']);
            $table->index(['item_type', 'item_kind']);
            $table->index('ext_c01', 'ix_item_ext_c01');
            $table->index('ext_c02', 'ix_item_ext_c02');
            $table->index('ext_c03', 'ix_item_ext_c03');
            $table->index('ext_c04', 'ix_item_ext_c04');
            $table->index('ext_c05', 'ix_item_ext_c05');
            $table->index('ext_d01', 'ix_item_ext_d01');
            $table->index('ext_d02', 'ix_item_ext_d02');
            $table->index('ext_n01', 'ix_item_ext_n01');
            $table->index('ext_n02', 'ix_item_ext_n02');
            $table->index('ext_n03', 'ix_item_ext_n03');
            $table->comment('품목 마스터');
        });

        Schema::create('partner', function (Blueprint $table) {
            $table->id('partner_idx')->comment('거래처 IDX');
            $table->string('partner_code', 20)->comment('거래처코드 ( 유일 )');
            $table->string('partner_name', 60)->comment('거래처명');
            $table->enum('is_supplier', ['Y', 'N'])->default('Y')->comment('구매처 여부');
            $table->enum('is_customer', ['Y', 'N'])->default('Y')->comment('고객사 여부');
            $table->enum('is_subcon', ['Y', 'N'])->default('N')->comment('외주처 여부');
            $table->string('biz_no', 20)->nullable()->comment('사업자번호');
            $table->string('ceo_name', 50)->nullable()->comment('대표자');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->unique('partner_code');
            $table->comment('거래처');
        });

        Schema::create('partner_site', function (Blueprint $table) {
            $table->id('site_idx')->comment('출고지 IDX');
            $table->foreignId('partner_idx')->comment('거래처 IDX')->constrained(table: 'partner', column: 'partner_idx')->cascadeOnDelete();
            $table->string('site_name', 60)->comment('출고지명');
            $table->string('address', 200)->nullable()->comment('주소');
            $table->string('tel', 30)->nullable()->comment('대표 연락처');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('거래처 출고지');
        });

        Schema::create('routing', function (Blueprint $table) {
            $table->id('routing_idx')->comment('라우팅 IDX');
            $table->enum('owner_type', ['TEMPLATE', 'ITEM', 'ORDER_LINE'])->default('TEMPLATE')->comment('단계 : TEMPLATE 공용 틀 · ITEM 품목 전용 · ORDER_LINE 수주 줄 전용');
            $table->unsignedBigInteger('item_idx')->nullable()->comment('품목 전용 라우팅의 품목');
            $table->unsignedBigInteger('sales_order_line_idx')->nullable()->comment('수주 줄 전용 라우팅의 수주 줄 ( 줄을 지우면 같이 )');
            $table->unsignedBigInteger('base_routing_idx')->nullable()->comment('복사해 온 원본 라우팅 ( 품목 ← 틀 · 수주 ← 품목 )');
            $table->string('routing_code', 30)->comment('라우팅코드 ( 유일 )');
            $table->string('routing_name', 60)->comment('라우팅명');
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('기본 라우팅 여부 ( 하나만 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->foreign('base_routing_idx')->references('routing_idx')->on('routing')->nullOnDelete();
            $table->index('is_use');
            $table->unique('item_idx');
            $table->index('owner_type');
            $table->unique('routing_code');
            $table->unique('sales_order_line_idx');
            $table->foreign('item_idx')->references('item_idx')->on('item');
            $table->comment('공정 라우팅');
        });

        Schema::create('routing_line', function (Blueprint $table) {
            $table->id('routing_line_idx')->comment('라우팅 줄 IDX ( BOM 투입 지점이 참조 )');
            $table->unsignedBigInteger('routing_idx')->comment('라우팅 IDX');
            $table->unsignedBigInteger('source_routing_line_idx')->nullable()->comment('복사해 온 원본 줄 ( 화면에서 더한 공정이면 NULL )');
            $table->unsignedSmallInteger('seq')->comment('순서 ( 1부터 )');
            $table->foreignId('process_idx')->comment('공정 IDX')->constrained(table: 'process', column: 'process_idx')->restrictOnDelete();
            $table->decimal('cycle_time_sec', 10, 2)->default(0)->comment('개당 표준시간 ( 초 ) — 수량 기반 일정의 원본');
            $table->enum('insp_first', ['Y', 'N'])->default('N')->comment('초물 검사');
            $table->enum('insp_middle', ['Y', 'N'])->default('N')->comment('중물 검사');
            $table->enum('insp_last', ['Y', 'N'])->default('N')->comment('종물 검사');
            $table->unsignedTinyInteger('pre_approve_min')->default(0)->comment('선행 진행에 필요한 승인 수 ( 0 = 불가 )');
            $table->enum('pre_approved_by_design', ['Y', 'N'])->default('N')->comment('설계 승인 ( 매번 묻지 않음 )');
            $table->integer('pre_approved_by')->nullable()->comment('설계 승인을 켠 사람');
            $table->timestamp('pre_approved_at')->nullable()->comment('설계 승인을 켠 시각');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['routing_idx', 'seq']);
            $table->index('source_routing_line_idx');
            $table->foreign('routing_idx')->references('routing_idx')->on('routing')->cascadeOnDelete();
            $table->comment('라우팅 공정 줄');
        });

        Schema::create('routing_change_log', function (Blueprint $table) {
            $table->id('log_idx')->comment('이력 IDX');
            $table->enum('scope', ['ITEM', 'ORDER_LINE', 'WORK_ORDER'])->comment('대상 구분');
            $table->unsignedBigInteger('ref_idx')->comment('item_idx · sales_order_line_idx · work_order_idx');
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('대상 라우팅 ( 작업지시면 NULL )');
            $table->enum('action', ['CREATE', 'ADD', 'REMOVE', 'MOVE', 'UPDATE', 'RELOAD'])->comment('CREATE 복사로 생김 · ADD · REMOVE · MOVE 순서 · UPDATE 값 · RELOAD 원본에서 다시 불러옴');
            $table->unsignedBigInteger('process_idx')->nullable()->comment('대상 공정 ( CREATE · RELOAD 는 NULL )');
            $table->unsignedSmallInteger('seq_before')->nullable()->comment('바뀌기 전 순서');
            $table->unsignedSmallInteger('seq_after')->nullable()->comment('바뀐 뒤 순서');
            $table->json('before_json')->nullable()->comment('바뀌기 전 값 ( CREATE · RELOAD 는 공정 목록 )');
            $table->json('after_json')->nullable()->comment('바뀐 뒤 값');
            $table->integer('create_account_idx')->comment('변경한 사람');
            $table->timestamp('create_datetime')->useCurrent()->comment('변경 시각');

            $table->index(['scope', 'ref_idx', 'log_idx']);
            $table->comment('공정 라우팅 변경 이력');
        });

        Schema::create('bom', function (Blueprint $table) {
            $table->id('bom_idx')->comment('BOM IDX');
            $table->enum('owner_type', ['ITEM', 'ORDER_LINE'])->default('ITEM')->comment('ITEM 품목 BOM · ORDER_LINE 수주 줄 자재');
            $table->unsignedBigInteger('sales_order_line_idx')->nullable()->comment('수주 줄 자재의 수주 줄 ( 줄을 지우면 같이 )');
            $table->unsignedBigInteger('base_bom_idx')->nullable()->comment('복사해 온 원본 BOM');
            $table->unsignedBigInteger('item_idx')->comment('품목 IDX ( 이 BOM 의 주인 )');
            $table->string('version', 20)->comment('버전 ( 자유 문자열 : V1 · A · 1.0 )');
            $table->enum('is_active', ['Y', 'N'])->default('N')->comment('활성 여부 ( 품목당 하나 )');
            $table->timestamp('activated_at')->nullable()->comment('활성으로 돌린 시각 ( 시스템 )');
            $table->date('changed_at')->nullable()->comment('설계변경 발효일 ( 업체 입력 )');
            $table->timestamp('approved_at')->nullable()->comment('변경 승인일 ( [승인] 누른 시각 )');
            $table->integer('approved_by')->nullable()->comment('변경 승인자');
            $table->text('remark')->nullable()->comment('변경 사유 · 비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->foreign('base_bom_idx')->references('bom_idx')->on('bom')->nullOnDelete();
            $table->index(['item_idx', 'is_active']);
            $table->unique(['item_idx', 'version']);
            $table->index('owner_type');
            $table->unique('sales_order_line_idx');
            $table->foreign('item_idx')->references('item_idx')->on('item')->restrictOnDelete();
            $table->comment('BOM ( 품목 × 버전 )');
        });

        Schema::create('bom_line', function (Blueprint $table) {
            $table->id('bom_line_idx')->comment('BOM 줄 IDX');
            $table->unsignedBigInteger('bom_idx')->comment('BOM IDX');
            $table->unsignedBigInteger('source_bom_line_idx')->nullable()->comment('복사해 온 원본 줄 ( 화면에서 더한 자재면 NULL )');
            $table->unsignedSmallInteger('seq')->default(0)->comment('표시 순서');
            $table->foreignId('child_item_idx')->comment('자재 품목 IDX')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->foreignId('routing_line_idx')->nullable()->comment('투입 지점 ( 라우팅 줄. NULL = 첫 수량 공정 )')->constrained(table: 'routing_line', column: 'routing_line_idx')->nullOnDelete();
            $table->decimal('qty', 15, 4)->comment('소요 ( 제품 1개당 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 비우면 품목 → 정책 순 )');
            $table->decimal('ext_n01', 15, 3)->nullable()->comment('기능형 값');
            $table->decimal('ext_n02', 18, 6)->nullable()->comment('기능형 슬롯');
            $table->decimal('ext_n03', 18, 6)->nullable()->comment('기능형 슬롯');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['bom_idx', 'seq']);
            $table->foreign('bom_idx')->references('bom_idx')->on('bom')->cascadeOnDelete();
            $table->comment('BOM 자재 줄');
        });

        Schema::create('bom_line_substitute', function (Blueprint $table) {
            $table->id('bom_line_substitute_idx')->comment('대체 자재 IDX');
            $table->unsignedBigInteger('bom_line_idx')->comment('주 자재 줄');
            $table->unsignedSmallInteger('seq')->default(0)->comment('순서 ( 우선순위 · 표시 )');
            $table->foreignId('child_item_idx')->comment('대체 자재 품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 15, 4)->comment('소요 ( 제품 1개당 — 대체 자재 기준 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 비우면 품목 → 정책 순 )');
            $table->decimal('ext_n01', 15, 3)->nullable()->comment('기능형 값');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['bom_line_idx', 'child_item_idx']);
            $table->index(['bom_line_idx', 'seq']);
            $table->foreign('bom_line_idx')->references('bom_line_idx')->on('bom_line')->cascadeOnDelete();
            $table->comment('BOM 대체 자재');
        });

        Schema::create('bom_change_log', function (Blueprint $table) {
            $table->id('log_idx')->comment('이력 IDX');
            $table->enum('scope', ['ORDER_LINE', 'ITEM'])->comment('대상 구분 ( ORDER_LINE 수주 자재 · ITEM 품목 BOM )');
            $table->unsignedBigInteger('ref_idx')->comment('sales_order_line_idx');
            $table->unsignedBigInteger('bom_idx')->nullable()->comment('대상 BOM');
            $table->enum('action', ['CREATE', 'RELOAD', 'ADD', 'REMOVE', 'UPDATE', 'SUB_ADD', 'SUB_REMOVE', 'SUB_UPDATE'])->comment('CREATE 복사로 생김 · RELOAD 원본에서 다시 · ADD · REMOVE · UPDATE 자재 줄 · SUB_* 대체 자재');
            $table->unsignedBigInteger('child_item_idx')->nullable()->comment('대상 자재 ( 대체 자재 이력이면 그 대체 자재 )');
            $table->unsignedBigInteger('main_item_idx')->nullable()->comment('대체 자재 이력의 주 자재');
            $table->json('before_json')->nullable()->comment('바뀌기 전 값 ( CREATE · RELOAD 는 자재 목록 )');
            $table->json('after_json')->nullable()->comment('바뀐 뒤 값');
            $table->integer('create_account_idx')->comment('변경한 사람');
            $table->timestamp('create_datetime')->useCurrent()->comment('변경 시각');

            $table->index(['scope', 'ref_idx', 'log_idx']);
            $table->comment('수주 자재 변경 이력');
        });

        Schema::create('inspection_item', function (Blueprint $table) {
            $table->id('inspection_item_idx')->comment('검사항목 IDX');
            $table->string('inspection_item_name', 60)->comment('항목명 ( 유일, 코드 없음 )');
            $table->enum('is_quantitative', ['Y', 'N'])->comment('성격 ( Y 정량 - 숫자 판정 / N 정성 - 문구 판정 )');
            $table->string('method', 60)->nullable()->comment('검사 방법 ( 캘리퍼 · 육안 · 막두께계 … )');
            $table->string('unit_code', 100)->nullable()->comment('단위 ( system_code UNIT 그룹 코드키, 정량만 )');
            $table->string('criteria', 500)->nullable()->comment('판정 기준 ( 정성만, 기준서에 그대로 복사 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('inspection_item_name');
            $table->index('is_use');
            $table->comment('검사항목');
        });

        Schema::create('inspection_spec', function (Blueprint $table) {
            $table->id('inspection_spec_idx')->comment('검사기준서 IDX');
            $table->string('inspection_spec_name', 100)->comment('기준서명');
            $table->unsignedBigInteger('item_idx')->comment('품목 IDX');
            $table->enum('insp_type', ['INCOMING', 'PROCESS', 'SHIPPING'])->comment('검사 종류 ( INCOMING 입고 / PROCESS 공정 / SHIPPING 출하 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 품목 × 검사 종류당 사용 중은 하나 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->index(['item_idx', 'insp_type']);
            $table->foreign('item_idx')->references('item_idx')->on('item')->restrictOnDelete();
            $table->comment('검사기준서');
        });

        Schema::create('inspection_spec_item', function (Blueprint $table) {
            $table->id('inspection_spec_item_idx')->comment('기준서 줄 IDX');
            $table->unsignedBigInteger('inspection_spec_idx')->comment('검사기준서 IDX');
            $table->unsignedSmallInteger('seq')->comment('순서 ( 1부터 )');
            $table->foreignId('inspection_item_idx')->nullable()->comment('출처 검사항목 IDX ( 직접 입력이면 NULL )')->constrained(table: 'inspection_item', column: 'inspection_item_idx')->nullOnDelete();
            $table->string('inspection_item_name', 60)->comment('항목명 ( 마스터에서 복사 또는 직접 입력 )');
            $table->enum('is_quantitative', ['Y', 'N'])->comment('성격 ( Y 정량 / N 정성 )');
            $table->string('method', 60)->nullable()->comment('검사 방법');
            $table->string('standard_value', 100)->nullable()->comment('규격 ( 정량만 · 글자 — 예: 100x300 )');
            $table->decimal('lower_limit', 15, 4)->nullable()->comment('하한 ( 정량만 )');
            $table->decimal('upper_limit', 15, 4)->nullable()->comment('상한 ( 정량만 )');
            $table->string('unit_code', 100)->nullable()->comment('단위 ( system_code UNIT 그룹 코드키, 정량만 )');
            $table->string('criteria', 500)->nullable()->comment('판정 기준 ( 정성만 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['inspection_spec_idx', 'seq']);
            $table->foreign('inspection_spec_idx')->references('inspection_spec_idx')->on('inspection_spec')->cascadeOnDelete();
            $table->comment('검사기준서 줄');
        });

        Schema::create('lead_time', function (Blueprint $table) {
            $table->id('lead_time_idx')->comment('리드타임 IDX');
            $table->unsignedBigInteger('item_idx')->nullable()->comment('품목 IDX ( NULL = 기본값 )');
            $table->unsignedSmallInteger('purchase_days')->nullable()->comment('구매 일수 ( NULL = 해당 없음 )');
            $table->unsignedSmallInteger('receive_days')->nullable()->comment('입고 일수');
            $table->unsignedSmallInteger('produce_days')->nullable()->comment('생산 일수');
            $table->unsignedSmallInteger('inspect_days')->nullable()->comment('검사 일수');
            $table->unsignedSmallInteger('pack_days')->nullable()->comment('포장 일수');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 미사용이면 기본값을 씀, 기본값 행은 내릴 수 없음 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->unique('item_idx');
            $table->foreign('item_idx')->references('item_idx')->on('item')->restrictOnDelete();
            $table->comment('제조리드타임');
        });

        Schema::create('lot_rule', function (Blueprint $table) {
            $table->id('lot_rule_idx')->comment('LOT 규칙 IDX');
            $table->string('lot_rule_name', 60)->comment('규칙명');
            $table->enum('rule_type', ['MATERIAL', 'PRODUCE_START', 'PRODUCE_END', 'SPLIT', 'SHIPPING', 'SERIAL'])->comment('기준 ( MATERIAL 자재-입고일 / PRODUCE_START 생산일-지시 시작일 / PRODUCE_END 종료일-지시 완료일 / SPLIT 분할생산-설비·작업자 변경 시점 / SHIPPING 출하일-출하등록일 / SERIAL 시리얼-LOT 단위 )');
            $table->json('format_spec')->comment('조합 요소 목록 ( 순서 있음 )');
            $table->string('separator', 3)->default('-')->comment('요소 사이 구분자 ( 빈 값이면 붙여 씀 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['rule_type', 'is_use']);
            $table->comment('LOT 규칙');
        });

        Schema::create('partner_contact', function (Blueprint $table) {
            $table->id('contact_idx')->comment('담당자 IDX');
            $table->foreignId('partner_idx')->comment('거래처 IDX')->constrained(table: 'partner', column: 'partner_idx')->cascadeOnDelete();
            $table->string('contact_type', 100)->comment('구분 ( system_code PARTNER_CONTACT_TYPE 코드키 )');
            $table->foreignId('site_idx')->nullable()->comment('소속 출고지 IDX ( NULL = 본사 )')->constrained(table: 'partner_site', column: 'site_idx')->nullOnDelete();
            $table->string('contact_name', 50)->comment('이름');
            $table->string('phone', 30)->nullable()->comment('연락처');
            $table->string('email', 100)->nullable()->comment('이메일');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('거래처 담당자');
        });
    }

    public function down(): void {
        Schema::dropIfExists('partner_contact');
        Schema::dropIfExists('lot_rule');
        Schema::dropIfExists('lead_time');
        Schema::dropIfExists('inspection_spec_item');
        Schema::dropIfExists('inspection_spec');
        Schema::dropIfExists('inspection_item');
        Schema::dropIfExists('bom_change_log');
        Schema::dropIfExists('bom_line_substitute');
        Schema::dropIfExists('bom_line');
        Schema::dropIfExists('bom');
        Schema::dropIfExists('routing_change_log');
        Schema::dropIfExists('routing_line');
        Schema::dropIfExists('routing');
        Schema::dropIfExists('partner_site');
        Schema::dropIfExists('partner');
        Schema::dropIfExists('item');
        Schema::dropIfExists('process_equipment');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('process');
        Schema::dropIfExists('warehouse');
        Schema::dropIfExists('calc_def');
    }
};

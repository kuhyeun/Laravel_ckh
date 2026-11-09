<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 생산 ( 계획 · 작업지시 · 공정 · 실적 · 자재 · 재공 · 외주 )
//   현재 구조를 첫 기준으로 다시 씀 ( 2026-11-09 — 개발 중 쌓인 마이그레이션 77개를 정리 )
//   production_plan             : 생산계획
//   production_plan_source      : 생산계획 ↔ 수주 줄 ( 계획 하나에 수주 여럿 )
//   work_order_sheet            : 작업지시서 ( 품목 줄 = work_order )
//   wo_stock                    : 작업지시 보유 자재 ( 보유 = 출고 − 사용 − 반납 )
//   wip_txn                     : 재공 이력 ( 공정 사이 반제품 — 현재 재공 = 지시 × 공정 합계 )
//   subcon_issue                : 외주 출고
//   subcon_receipt              : 외주 입고
//   work_order                  : 작업지시
//   work_order_source           : 작업지시 ↔ 수주 줄 ( 분할 · 병합 )
//   work_order_op               : 작업지시 공정 ( 라우팅 사본 )
//   work_order_material         : 작업지시 자재 ( BOM 사본 )
//   work_order_op_run           : 공정 실적 ( 입력 1건 = 1줄 )
//
// ── 처음 만들 때의 설계 메모 ──
// 생산계획 ( 선택 단계 — 수주 → 계획 → 작업지시 / 수주 → 작업지시 둘 다 ) — docs/생산계획-설계.md
//   계획 하나 = 품목 하나 · 기간 · 수주 줄 여럿( production_plan_source — 작업지시 출처와 같은 방식 )
//   공정 · 자재는 수주 줄의 것 ( 담긴 수주 줄은 공정 · 자재가 같아야 한다 — 그대로 작업지시 줄 하나가 된다 )
//   작업지시 품목 줄( work_order )이 production_plan_idx 로 계획을 가리킨다 ( 자리는 10-12 에 만들어 둠 → 여기서 FK )
//
// 작업지시서 ( 1단계 보완 — 여러 품목을 지시 한 장으로 ) — docs/screens/50-생산.md "1단계 보완"
//   work_order_sheet  : 지시서 머리 ( 착수 · 기한 · 담당 · 비고 ). 상태는 품목 줄에서 정해진다
//   work_order        : 품목 줄 = 지금의 작업지시 그대로 + sheet_idx · line_no ( 번호 WO-YYMMDD-NNN-01 )
//   work_order_source : 병합 이력 유지 — 저장 때 지우고 다시 넣지 않고 바뀐 것만 고친다 ( 수정자 · 수정일 )
//   기존 작업지시 데이터는 0 건이라 옮길 것이 없다 ( sheet_idx 는 필수 )
//
// 재공 이력 ( 자재관리 > 재공관리 3010 ) — 공정 실적마다 생산( + ) · 다음 공정 투입( − ) 줄 / WipService
//   만들 때 지금까지의 실적( 취소 안 된 것 )으로 채운다 · 메뉴 3010 을 화면에 연결
//
// 외주관리 ( 4005 ) — SubconService
//   subcon_issue   : 외주 출고 ( 지시 공정 하나 · 외주처 · 수량 · 외주 단가 / 금액 — 기록만 )
//   subcon_receipt : 외주 입고 ( 출고 건마다 여러 번 · 검사대기 → 검사 → 완료 / 검사 안 쓰면 바로 완료 ) — 합격분이 외주 공정 실적
//   inspection.insp_type 에 SUBCON · wip_txn 에 외주 출고 연결 · 메뉴 4005 하위 4개 연결
//
// 작업지시 1단계 ( PRD-02 · PRD-03 ) — docs/screens/50-생산.md §1 · ERD §9
//   work_order          : 머리. 지시 시점의 BOM 버전 고정 · 생산 LOT 발급
//   work_order_source   : 지시 ↔ 수주 줄 ( 분할 · 병합 — 한 지시에 수주 줄 여럿, 한 수주 줄에 지시 여럿 )
//   work_order_op       : 라우팅의 사본 ( 순서 · 추가 · 삭제 가능, 라우팅이 바뀌어도 소급 안 됨 )
//   work_order_material : BOM 줄의 사본 ( 같은 버전의 줄도 나중에 고칠 수 있어서 ) — 2단계 출고가 쓴다
//   실적( 실행 · 선행 승인 · wo_stock )은 3단계에서
//
// 생산 3단계 최소판 — 공정실적 ( PRD-06 ) · docs/screens/50-생산.md "3단계"
//   work_order_op_run : 공정 실적. 입력 1건 = 1줄 ( 공정 × 설비 × 작업자 × 수량 × 작업 일시 ). 취소는 지우지 않고 표시
//                       작업자( operator_idx )는 기본 로그인 · 바꿀 수 있음, 입력자는 create_account_idx ( 다르면 대리 입력 — ERD §9 )
//   work_order_op     : 시작 · 완료 시각 ( status · qty · short_qty · short_reason 은 1단계에 있음 )
//   work_order_source : 이 품목 줄이 그 수주 줄에 반영한 생산 수량 ( 완료 취소 때 그대로 되돌린다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('production_plan', function (Blueprint $table) {
            $table->id('production_plan_idx')->comment('생산계획 IDX');
            $table->string('plan_no', 30)->comment('계획번호 ( PP-YYMMDD-NNN — 등록일 기준 )');
            $table->foreignId('item_idx')->comment('품목 ( 담긴 수주 줄의 품목 — 모두 같다 )')->constrained(table: 'item', column: 'item_idx');
            $table->decimal('qty', 18, 6)->comment('계획 수량 ( 출처 수량 합 )');
            $table->date('start_date')->comment('생산 시작일');
            $table->date('end_date')->comment('완료 예정일');
            $table->enum('status', ['PLANNED', 'CANCELED'])->default('PLANNED')->comment('PLANNED 계획 · CANCELED 취소 ( 지시 일부 · 완료는 지시 수량으로 계산 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique('plan_no');
            $table->index(['start_date', 'end_date']);
            $table->index(['status', 'start_date']);
            $table->comment('생산계획');
        });

        Schema::create('production_plan_source', function (Blueprint $table) {
            $table->id('production_plan_source_idx')->comment('계획 출처 IDX');
            $table->unsignedBigInteger('production_plan_idx')->comment('생산계획');
            $table->unsignedBigInteger('sales_order_line_idx')->comment('수주 줄');
            $table->decimal('qty', 18, 6)->comment('이 계획에 담은 수량');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index('sales_order_line_idx');
            $table->unique(['production_plan_idx', 'sales_order_line_idx'], 'uk_plan_source');
            $table->foreign('production_plan_idx')->references('production_plan_idx')->on('production_plan')->cascadeOnDelete();
            $table->foreign('sales_order_line_idx')->references('sales_order_line_idx')->on('sales_order_line')->restrictOnDelete();
            $table->comment('생산계획 ↔ 수주 줄 ( 계획 하나에 수주 여럿 )');
        });

        Schema::create('work_order_sheet', function (Blueprint $table) {
            $table->id('sheet_idx')->comment('작업지시서 IDX');
            $table->string('sheet_no', 30)->comment('지시서 번호 ( WO-YYMMDD-NNN )');
            $table->date('start_date')->comment('착수 예정일 ( 모든 품목 줄에 적용 )');
            $table->date('due_date')->comment('생산 기한 ( 모든 품목 줄에 적용 )');
            $table->integer('manager_idx')->nullable()->comment('담당자 ( 사용자 )');
            $table->enum('status', ['WAIT', 'RUN', 'DONE', 'CANCELED'])->default('WAIT')->comment('상태 ( 품목 줄에서 정해짐 — WAIT 대기 / RUN 진행 / DONE 완료 / CANCELED 취소 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('due_date');
            $table->unique('sheet_no');
            $table->index(['status', 'start_date']);
            $table->comment('작업지시서 ( 품목 줄 = work_order )');
        });

        Schema::create('wo_stock', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시');
            $table->unsignedBigInteger('item_idx')->comment('자재 품목');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT ( 0 = LOT 미사용 )');
            $table->decimal('issued_qty', 18, 6)->default(0)->comment('출고 합계');
            $table->decimal('consumed_qty', 18, 6)->default(0)->comment('사용처리 합계 ( 원장에 남지 않음 )');
            $table->decimal('returned_qty', 18, 6)->default(0)->comment('반납 합계 ( 반납 취소 반영 )');
            $table->decimal('qty', 18, 6)->default(0)->comment('보유 = 출고 − 사용');
            $table->timestamp('update_datetime')->nullable()->useCurrent()->useCurrentOnUpdate()->comment('수정일');

            $table->primary(['work_order_idx', 'item_idx', 'lot_idx']);
            $table->index(['item_idx', 'lot_idx']);
            $table->comment('작업지시 보유 자재 ( 보유 = 출고 − 사용 − 반납 )');
        });

        Schema::create('wip_txn', function (Blueprint $table) {
            $table->id('wip_txn_idx')->comment('재공 이력 IDX');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시( 품목 줄 )');
            $table->unsignedBigInteger('work_order_op_idx')->comment('재공이 있는 공정');
            $table->unsignedBigInteger('item_idx')->comment('품목 ( 지시 품목 )');
            $table->unsignedBigInteger('run_idx')->nullable()->comment('원인 실적');
            $table->unsignedBigInteger('subcon_issue_idx')->nullable()->comment('원인 외주 출고 ( 앞 공정 − )');
            $table->enum('kind', ['MAKE', 'FEED'])->comment('MAKE 생산( + ) / FEED 다음 공정 투입( − )');
            $table->enum('is_reversal', ['Y', 'N'])->default('N')->comment('실적 취소로 생긴 반대 줄');
            $table->decimal('qty', 18, 6)->comment('수량 ( + 늘어남 / − 줄어듦 )');
            $table->unsignedBigInteger('to_op_idx')->nullable()->comment('FEED : 가져간 공정');
            $table->dateTime('occurred_at')->comment('일시 ( 실적 작업 일시 · 취소 줄은 취소 일시 )');
            $table->integer('operator_idx')->nullable()->comment('작업자 ( 원인 실적 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index('occurred_at');
            $table->index('run_idx');
            $table->index('subcon_issue_idx');
            $table->index(['work_order_idx', 'work_order_op_idx']);
            $table->comment('재공 이력 ( 공정 사이 반제품 — 현재 재공 = 지시 × 공정 합계 )');
        });

        Schema::create('subcon_issue', function (Blueprint $table) {
            $table->id('subcon_issue_idx')->comment('외주 출고 IDX');
            $table->string('issue_no', 30)->comment('출고 번호 ( SC-YYMMDD-NNN )');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시( 품목 줄 )');
            $table->unsignedBigInteger('work_order_op_idx')->comment('외주 공정');
            $table->unsignedBigInteger('partner_idx')->comment('외주처');
            $table->decimal('qty', 18, 6)->comment('출고 수량');
            $table->decimal('amount', 18, 2)->default(0)->comment('외주 금액 ( 총 비용 · 입력 그대로 · 기록만 )');
            $table->dateTime('issued_at')->comment('출고 일시');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('출고 취소 ( 입고가 없을 때만 )');
            $table->integer('canceled_by')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->unique('issue_no');
            $table->index('issued_at');
            $table->index(['work_order_op_idx', 'is_canceled']);
            $table->comment('외주 출고');
        });

        Schema::create('subcon_receipt', function (Blueprint $table) {
            $table->id('subcon_receipt_idx')->comment('외주 입고 IDX');
            $table->string('receipt_no', 30)->comment('입고 번호 ( SR-YYMMDD-NNN )');
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

            $table->unique('receipt_no');
            $table->index('received_at');
            $table->index('run_idx');
            $table->index(['subcon_issue_idx', 'status']);
            $table->comment('외주 입고');
        });

        Schema::create('work_order', function (Blueprint $table) {
            $table->id('work_order_idx')->comment('작업지시 IDX');
            $table->unsignedBigInteger('sheet_idx')->comment('작업지시서');
            $table->unsignedSmallInteger('line_no')->comment('지시서 안의 줄 순번 ( 01 · 02 … )');
            $table->string('wo_no', 30)->comment('지시번호 ( WO-YYMMDD-NNN )');
            $table->enum('source', ['ORDER', 'STOCK', 'MANUAL'])->comment('출처 ( ORDER 수주 / STOCK 재고 / MANUAL 수동 )');
            $table->unsignedBigInteger('production_plan_idx')->nullable()->comment('생산계획 참조 ( Phase 2 — 계획에서 만든 지시 )');
            $table->unsignedBigInteger('item_idx')->comment('만들 품목');
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('복사해 온 라우팅 ( 표시용 )');
            $table->unsignedBigInteger('bom_idx')->nullable()->comment('지시 시점의 BOM 버전 ( 고정 )');
            $table->unsignedBigInteger('lot_idx')->nullable()->comment('생산 LOT ( 지시 저장 때 발급 )');
            $table->unsignedBigInteger('lot_rule_idx')->nullable()->comment('발급한 LOT 규칙');
            $table->decimal('target_qty', 18, 6)->comment('지시 수량 ( 미달로 끝나도 고치지 않는다 )');
            $table->decimal('scan_qty', 18, 6)->default(1)->comment('회당 상승 수량 ( 라우트시트 · 스캔 기본값 )');
            $table->date('start_date')->comment('착수 예정일');
            $table->date('due_date')->comment('생산 기한');
            $table->enum('status', ['WAIT', 'RUN', 'DONE', 'CANCELED'])->default('WAIT')->comment('상태 ( WAIT 대기 / RUN 진행 / DONE 완료 / CANCELED 취소 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->comment('결재 ( Phase 1 NONE )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('due_date');
            $table->index(['item_idx', 'status']);
            $table->index('production_plan_idx');
            $table->unique(['sheet_idx', 'line_no']);
            $table->index(['status', 'start_date']);
            $table->unique('wo_no');
            $table->foreign('item_idx')->references('item_idx')->on('item')->restrictOnDelete();
            $table->foreign('production_plan_idx')->references('production_plan_idx')->on('production_plan')->nullOnDelete();
            $table->foreign('sheet_idx')->references('sheet_idx')->on('work_order_sheet')->restrictOnDelete();
            $table->comment('작업지시');
        });

        Schema::create('work_order_source', function (Blueprint $table) {
            $table->id('work_order_source_idx')->comment('연결 IDX');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시');
            $table->unsignedBigInteger('sales_order_line_idx')->comment('수주 줄');
            $table->decimal('qty', 18, 6)->comment('이 지시에 담은 수량');
            $table->decimal('produced_qty', 18, 6)->default(0)->comment('이 품목 줄이 이 수주 줄에 반영한 생산 수량');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('sales_order_line_idx');
            $table->unique(['work_order_idx', 'sales_order_line_idx']);
            $table->foreign('sales_order_line_idx')->references('sales_order_line_idx')->on('sales_order_line')->restrictOnDelete();
            $table->foreign('work_order_idx')->references('work_order_idx')->on('work_order')->cascadeOnDelete();
            $table->comment('작업지시 ↔ 수주 줄 ( 분할 · 병합 )');
        });

        Schema::create('work_order_op', function (Blueprint $table) {
            $table->id('work_order_op_idx')->comment('지시 공정 IDX');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시');
            $table->unsignedSmallInteger('seq')->comment('순서 ( OP 010 · 020 … )');
            $table->foreignId('process_idx')->comment('공정')->constrained(table: 'process', column: 'process_idx')->restrictOnDelete();
            $table->unsignedBigInteger('routing_line_idx')->nullable()->comment('복사해 온 라우팅 줄 ( 지시에서 더한 공정이면 NULL )');
            $table->unsignedBigInteger('equipment_idx')->nullable()->comment('지정 설비 ( 공정의 설비 후보 중 )');
            $table->integer('worker_idx')->nullable()->comment('지정 작업자');
            $table->decimal('cycle_time_sec', 10, 2)->default(0)->comment('개당 표준시간 ( 초 )');
            $table->date('due_date')->nullable()->comment('공정별 기한');
            $table->enum('insp_first', ['Y', 'N'])->default('N')->comment('초물 ( 초중종 검사 공정만 )');
            $table->enum('insp_middle', ['Y', 'N'])->default('N')->comment('중물');
            $table->enum('insp_last', ['Y', 'N'])->default('N')->comment('종물');
            $table->unsignedTinyInteger('pre_approve_min')->default(0)->comment('종물 전 선행 진행 승인 인원 ( 0 = 불가 )');
            $table->enum('pre_approved_by_design', ['Y', 'N'])->default('N')->comment('설계 승인 ( 라우팅에서 복사 )');
            $table->enum('status', ['WAIT', 'RUN', 'DONE'])->default('WAIT')->comment('공정 상태 ( 3단계 )');
            $table->dateTime('started_at')->nullable()->comment('첫 실적 · 완료 처리 시각');
            $table->dateTime('done_at')->nullable()->comment('공정 완료 시각');
            $table->decimal('qty', 18, 6)->default(0)->comment('실적 수량 ( 3단계 — 실행 합 )');
            $table->decimal('short_qty', 18, 6)->nullable()->comment('미달 수량 ( 3단계 )');
            $table->string('short_reason', 50)->nullable()->comment('미달 사유 ( 3단계 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['work_order_idx', 'seq']);
            $table->foreign('work_order_idx')->references('work_order_idx')->on('work_order')->cascadeOnDelete();
            $table->comment('작업지시 공정 ( 라우팅 사본 )');
        });

        Schema::create('work_order_material', function (Blueprint $table) {
            $table->id('work_order_material_idx')->comment('지시 자재 IDX');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시');
            $table->unsignedSmallInteger('seq')->default(0)->comment('표시 순서');
            $table->unsignedBigInteger('child_item_idx')->comment('자재 품목');
            $table->unsignedBigInteger('bom_line_idx')->nullable()->comment('복사해 온 BOM 줄');
            $table->unsignedBigInteger('substitute_of_idx')->nullable()->comment('대체 자재 행이면 주 자재 행 IDX');
            $table->unsignedBigInteger('bom_line_substitute_idx')->nullable()->comment('복사해 온 대체 자재');
            $table->unsignedBigInteger('work_order_op_idx')->nullable()->comment('투입 공정 ( NULL = 첫 수량 공정 )');
            $table->decimal('qty_per', 18, 6)->comment('제품 1개당 소요');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 2단계 출고에서 계산 )');
            $table->decimal('ext_n01', 18, 6)->nullable()->comment('기능형 값 ( 절단 길이 )');
            $table->decimal('required_qty', 18, 6)->default(0)->comment('소요량 ( 1단계 = 개당 × 지시 수량 )');
            $table->decimal('issued_qty', 18, 6)->default(0)->comment('출고 합계 ( 취소 반영 )');
            $table->decimal('returned_qty', 18, 6)->default(0)->comment('반납 합계 ( 반납 취소 반영 ) — 남은 소요 = 계산 − ( 출고 − 반납 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('child_item_idx');
            $table->index('substitute_of_idx');
            $table->index(['work_order_idx', 'seq']);
            $table->foreign('child_item_idx')->references('item_idx')->on('item')->restrictOnDelete();
            $table->foreign('substitute_of_idx')->references('work_order_material_idx')->on('work_order_material')->cascadeOnDelete();
            $table->foreign('work_order_idx')->references('work_order_idx')->on('work_order')->cascadeOnDelete();
            $table->comment('작업지시 자재 ( BOM 사본 )');
        });

        Schema::create('work_order_op_run', function (Blueprint $table) {
            $table->id('run_idx')->comment('공정 실적 IDX');
            $table->unsignedBigInteger('work_order_op_idx')->comment('지시 공정');
            $table->unsignedBigInteger('equipment_idx')->nullable()->comment('실제 쓴 설비 ( 설비 공정만 )');
            $table->integer('operator_idx')->comment('작업자 ( 기본 로그인 사용자 · 바꿀 수 있음 )');
            $table->decimal('qty', 18, 6)->comment('이번에 한 수량');
            $table->dateTime('worked_at')->comment('작업 일시');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->unsignedBigInteger('stock_txn_line_idx')->nullable()->comment('이 실적으로 한 제품 입고 줄 ( 마지막 공정 · 부분 입고 )');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('실적 취소 ( 지우지 않음 )');
            $table->integer('canceled_by')->nullable()->comment('취소한 사람');
            $table->dateTime('canceled_at')->nullable()->comment('취소 일시');
            $table->integer('create_account_idx')->comment('입력자');
            $table->timestamp('create_datetime')->useCurrent()->comment('입력일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['work_order_op_idx', 'is_canceled']);
            $table->index('worked_at');
            $table->foreign('work_order_op_idx')->references('work_order_op_idx')->on('work_order_op')->restrictOnDelete();
            $table->comment('공정 실적 ( 입력 1건 = 1줄 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_op_run');
        Schema::dropIfExists('work_order_material');
        Schema::dropIfExists('work_order_op');
        Schema::dropIfExists('work_order_source');
        Schema::dropIfExists('work_order');
        Schema::dropIfExists('subcon_receipt');
        Schema::dropIfExists('subcon_issue');
        Schema::dropIfExists('wip_txn');
        Schema::dropIfExists('wo_stock');
        Schema::dropIfExists('work_order_sheet');
        Schema::dropIfExists('production_plan_source');
        Schema::dropIfExists('production_plan');
    }
};

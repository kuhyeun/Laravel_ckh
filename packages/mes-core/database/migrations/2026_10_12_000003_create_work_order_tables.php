<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 작업지시 1단계 ( PRD-02 · PRD-03 ) — docs/screens/50-생산.md §1 · ERD §9
//   work_order          : 머리. 지시 시점의 BOM 버전 고정 · 생산 LOT 발급
//   work_order_source   : 지시 ↔ 수주 줄 ( 분할 · 병합 — 한 지시에 수주 줄 여럿, 한 수주 줄에 지시 여럿 )
//   work_order_op       : 라우팅의 사본 ( 순서 · 추가 · 삭제 가능, 라우팅이 바뀌어도 소급 안 됨 )
//   work_order_material : BOM 줄의 사본 ( 같은 버전의 줄도 나중에 고칠 수 있어서 ) — 2단계 출고가 쓴다
//   실적( 실행 · 선행 승인 · wo_stock )은 3단계에서
return new class extends Migration {

    public function up(): void {
        Schema::create('work_order', function (Blueprint $table) {
            $table->id('work_order_idx')->comment('작업지시 IDX');
            $table->string('wo_no', 30)->unique()->comment('지시번호 ( WO-YYMMDD-NNN )');
            $table->enum('source', ['ORDER', 'STOCK', 'MANUAL'])->comment('출처 ( ORDER 수주 / STOCK 재고 / MANUAL 수동 )');
            $table->foreignId('item_idx')->comment('만들 품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('복사해 온 라우팅 ( 표시용 )');
            $table->unsignedBigInteger('bom_idx')->nullable()->comment('지시 시점의 BOM 버전 ( 고정 )');
            $table->unsignedBigInteger('lot_idx')->nullable()->comment('생산 LOT ( 지시 저장 때 발급 )');
            $table->unsignedBigInteger('lot_rule_idx')->nullable()->comment('발급한 LOT 규칙');
            $table->decimal('target_qty', 18, 6)->comment('지시 수량 ( 미달로 끝나도 고치지 않는다 )');
            $table->decimal('scan_qty', 18, 6)->default(1)->comment('회당 상승 수량 ( 라우트시트 · 스캔 기본값 )');
            $table->date('start_date')->comment('착수 예정일');
            $table->date('due_date')->comment('생산 기한');
            $table->enum('status', ['WAIT', 'RUN', 'DONE', 'CANCELED'])->default('WAIT')
                ->comment('상태 ( WAIT 대기 / RUN 진행 / DONE 완료 / CANCELED 취소 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->comment('결재 ( Phase 1 NONE )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['status', 'start_date']);
            $table->index(['item_idx', 'status']);
            $table->index('due_date');
            $table->comment('작업지시');
        });

        Schema::create('work_order_source', function (Blueprint $table) {
            $table->id('work_order_source_idx')->comment('연결 IDX');
            $table->foreignId('work_order_idx')->comment('작업지시')->constrained(table: 'work_order', column: 'work_order_idx')->cascadeOnDelete();
            $table->foreignId('sales_order_line_idx')->comment('수주 줄')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('이 지시에 담은 수량');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->unique(['work_order_idx', 'sales_order_line_idx']);
            $table->index('sales_order_line_idx');
            $table->comment('작업지시 ↔ 수주 줄 ( 분할 · 병합 )');
        });

        Schema::create('work_order_op', function (Blueprint $table) {
            $table->id('work_order_op_idx')->comment('지시 공정 IDX');
            $table->foreignId('work_order_idx')->comment('작업지시')->constrained(table: 'work_order', column: 'work_order_idx')->cascadeOnDelete();
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
            $table->decimal('qty', 18, 6)->default(0)->comment('실적 수량 ( 3단계 — 실행 합 )');
            $table->decimal('short_qty', 18, 6)->nullable()->comment('미달 수량 ( 3단계 )');
            $table->string('short_reason', 50)->nullable()->comment('미달 사유 ( 3단계 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['work_order_idx', 'seq']);
            $table->comment('작업지시 공정 ( 라우팅 사본 )');
        });

        Schema::create('work_order_material', function (Blueprint $table) {
            $table->id('work_order_material_idx')->comment('지시 자재 IDX');
            $table->foreignId('work_order_idx')->comment('작업지시')->constrained(table: 'work_order', column: 'work_order_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq')->default(0)->comment('표시 순서');
            $table->foreignId('child_item_idx')->comment('자재 품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->unsignedBigInteger('bom_line_idx')->nullable()->comment('복사해 온 BOM 줄');
            $table->unsignedBigInteger('work_order_op_idx')->nullable()->comment('투입 공정 ( NULL = 첫 수량 공정 )');
            $table->decimal('qty_per', 18, 6)->comment('제품 1개당 소요');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 2단계 출고에서 계산 )');
            $table->decimal('ext_n01', 18, 6)->nullable()->comment('기능형 값 ( 절단 길이 )');
            $table->decimal('required_qty', 18, 6)->default(0)->comment('소요량 ( 1단계 = 개당 × 지시 수량 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['work_order_idx', 'seq']);
            $table->index('child_item_idx');
            $table->comment('작업지시 자재 ( BOM 사본 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_material');
        Schema::dropIfExists('work_order_op');
        Schema::dropIfExists('work_order_source');
        Schema::dropIfExists('work_order');
    }
};

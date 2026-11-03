<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 바코드 라벨 ( LBL-01 ~ 03 ) — 1D 바코드( Code128 )만 · 바코드 번호 = LOT 번호 / 출하번호-줄
//   label_template  : 라벨 양식 ( 크기 · 배치 종류 ). 기본 100 × 72mm ( Zebra ZD230 )
//   barcode_label   : 발행 1건 = 대상( LOT · 출하 줄 ) 하나 × 장수. 같은 번호 여러 장 · 재발행 = 같은 번호로 다시 인쇄 · 폐기는 상태만
//   label_print_log : 인쇄할 때마다 ( 인쇄 여부 · 횟수 )
return new class extends Migration {

    public function up(): void {
        Schema::create('label_template', function (Blueprint $table) {
            $table->id('label_template_idx')->comment('라벨 양식 IDX');
            $table->string('template_name', 50)->comment('양식 이름');
            $table->enum('target', ['LOT', 'SHIPMENT'])->comment('대상 — LOT( 자재식별표 · 현품표 ) / SHIPMENT( 출하 라벨 )');
            $table->unsignedSmallInteger('width_mm')->comment('가로 mm');
            $table->unsignedSmallInteger('height_mm')->comment('세로 mm');
            $table->string('layout', 30)->comment('배치 종류 ( 화면 LabelSheet 의 키 )');
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('대상별 기본 양식');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('라벨 양식');
        });

        Schema::create('barcode_label', function (Blueprint $table) {
            $table->id('label_idx')->comment('라벨 발행 IDX');
            $table->enum('target', ['LOT', 'SHIPMENT_LINE'])->comment('대상');
            $table->foreignId('lot_idx')->nullable()->comment('LOT ( target = LOT )')->constrained(table: 'lot', column: 'lot_idx')->restrictOnDelete();
            $table->foreignId('shipment_line_idx')->nullable()->comment('출하 줄 ( target = SHIPMENT_LINE )')
                ->constrained(table: 'shipment_line', column: 'shipment_line_idx')->restrictOnDelete();
            $table->string('barcode_no', 60)->comment('바코드 번호 — UNIQUE 아님 ( 같은 번호 재발행 )');
            $table->foreignId('label_template_idx')->comment('양식')->constrained(table: 'label_template', column: 'label_template_idx')->restrictOnDelete();
            $table->unsignedSmallInteger('label_count')->comment('장수 ( 같은 번호 )');
            $table->decimal('label_qty', 18, 6)->nullable()->comment('라벨 한 장의 수량 ( 표시용 )');
            $table->enum('status', ['ISSUED', 'SCRAPPED'])->default('ISSUED')->comment('ISSUED 발행 / SCRAPPED 폐기');
            $table->string('scrap_reason', 200)->nullable()->comment('폐기 사유');
            $table->integer('scrapped_by')->nullable()->comment('폐기한 사람');
            $table->dateTime('scrapped_at')->nullable()->comment('폐기 일시');
            $table->unsignedInteger('print_total')->default(0)->comment('인쇄한 장수 누계 ( 0 = 미인쇄 )');
            $table->dateTime('last_printed_at')->nullable()->comment('마지막 인쇄');
            $table->integer('create_account_idx')->comment('발행자');
            $table->timestamp('create_datetime')->useCurrent()->comment('발행일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('barcode_no');
            $table->index(['target', 'status']);
            $table->index('create_datetime');
            $table->comment('바코드 라벨 발행');
        });

        Schema::create('label_print_log', function (Blueprint $table) {
            $table->id('label_print_log_idx')->comment('인쇄 이력 IDX');
            $table->foreignId('label_idx')->comment('라벨 발행')->constrained(table: 'barcode_label', column: 'label_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('print_count')->comment('이번에 인쇄한 장수');
            $table->dateTime('printed_at')->comment('인쇄 일시');
            $table->integer('printed_by')->comment('인쇄한 사람');
            $table->comment('라벨 인쇄 이력');
        });

        // 기본 양식 — 100 × 72mm ( Zebra ZD230 · 업체에서 쓰던 크기 )
        DB::table('label_template')->insert([
            ['template_name' => '자재식별표 · 현품표 100×72', 'target' => 'LOT',      'width_mm' => 100, 'height_mm' => 72, 'layout' => 'LOT_STD',      'is_default' => 'Y', 'is_use' => 'Y', 'create_account_idx' => 1],
            ['template_name' => '출하 라벨 100×72',          'target' => 'SHIPMENT', 'width_mm' => 100, 'height_mm' => 72, 'layout' => 'SHIPMENT_STD', 'is_default' => 'Y', 'is_use' => 'Y', 'create_account_idx' => 1],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('label_print_log');
        Schema::dropIfExists('barcode_label');
        Schema::dropIfExists('label_template');
    }
};

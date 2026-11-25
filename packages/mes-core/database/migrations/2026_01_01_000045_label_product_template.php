<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 라벨 : 자재식별표 · 현품표 양식 따로 · 출하 라벨 없앰 ( 11-25 · MES_1125 #13 이어서 )
//   label_template.target : LOT( 자재식별표 — 자재 LOT ) · PRODUCT( 현품표 — 제품 LOT ) ( 전에는 LOT 하나 + SHIPMENT )
//     출하 라벨 양식 → 현품표 양식으로 ( 배치 그대로 · 출하 칸은 현품표 칸으로 : 출하번호 → LOT · 고객사 / 수주 → 지시 번호 )
//     출하 라벨 양식이 없으면 기본 자재식별표 양식을 복사해 현품표 양식을 만든다
//   barcode_label : 출하 줄 라벨( SHIPMENT_LINE ) 기록 · 인쇄 기록을 지우고 shipment_line_idx 칸을 뺀다 ( 대상 = LOT 만 )
return new class extends Migration {

    private const FIELD_MAP = ['shipment_no' => 'lot_no', 'shipment_line' => 'lot_no', 'order_no' => 'wo_no', 'customer_po_no' => 'wo_no', 'partner_name' => 'wo_no'];
    private const TEXT_MAP  = ['고객사' => '지시 번호', '출하번호' => 'LOT', '수주' => '지시 번호', '고객 발주번호' => '지시 번호', '출하일' => '생산일'];

    public function up(): void {
        // ---- 양식 ----
        DB::statement("ALTER TABLE label_template MODIFY target ENUM('LOT','PRODUCT','SHIPMENT') NOT NULL COMMENT '대상 — LOT( 자재식별표 · 자재 LOT ) / PRODUCT( 현품표 · 제품 LOT )'");
        foreach (DB::table('label_template')->where('target', 'SHIPMENT')->get() as $t) {
            $design = $t->design ? json_decode($t->design, true) : null;
            if ($design) {
                $design['cells'] = array_map(function ($c) {
                    if (($c['type'] ?? null) === 'FIELD' && isset(self::FIELD_MAP[$c['field'] ?? ''])) $c['field'] = self::FIELD_MAP[$c['field']];
                    if (($c['type'] ?? null) === 'TEXT' && isset(self::TEXT_MAP[trim((string) ($c['text'] ?? ''))])) $c['text'] = self::TEXT_MAP[trim((string) $c['text'])];
                    return $c;
                }, $design['cells'] ?? []);
            }
            DB::table('label_template')->where('label_template_idx', $t->label_template_idx)->update([
                'target'        => 'PRODUCT',
                'template_name' => str_replace('출하 라벨', '현품표', $t->template_name),
                'layout'        => $t->layout === 'SHIPMENT_STD' ? 'PRODUCT_STD' : $t->layout,
                'design'        => $design ? json_encode($design, JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
        // 출하 라벨 양식이 없던 설치 : 기본 자재식별표 양식을 복사해 현품표 양식 ( 기본 )
        if (!DB::table('label_template')->where('target', 'PRODUCT')->exists()) {
            $src = DB::table('label_template')->where('target', 'LOT')->orderByRaw("is_default = 'Y' desc")->orderBy('label_template_idx')->first();
            if ($src) {
                $row = (array) $src;
                unset($row['label_template_idx'], $row['update_account_idx'], $row['update_datetime']);
                DB::table('label_template')->insert(array_merge($row, [
                    'target' => 'PRODUCT', 'template_name' => '현품표 100×72', 'is_default' => 'Y', 'is_use' => 'Y', 'create_datetime' => now(),
                ]));
            }
        }
        // 현품표 기본 양식 하나 ( 사용 중 ) 보장
        if (!DB::table('label_template')->where('target', 'PRODUCT')->where('is_default', 'Y')->exists()) {
            $first = DB::table('label_template')->where('target', 'PRODUCT')->where('is_use', 'Y')->orderBy('label_template_idx')->value('label_template_idx');
            if ($first) DB::table('label_template')->where('label_template_idx', $first)->update(['is_default' => 'Y']);
        }
        // LOT 양식 이름 : 「자재식별표 · 현품표」 → 「자재식별표」
        DB::table('label_template')->where('target', 'LOT')->where('template_name', 'like', '%자재식별표 · 현품표%')
            ->update(['template_name' => DB::raw("REPLACE(template_name, '자재식별표 · 현품표', '자재식별표')")]);
        DB::statement("ALTER TABLE label_template MODIFY target ENUM('LOT','PRODUCT') NOT NULL COMMENT '대상 — LOT( 자재식별표 · 자재 LOT ) / PRODUCT( 현품표 · 제품 LOT )'");

        // ---- 출하 줄 라벨 지우기 ----
        $ids = DB::table('barcode_label')->where('target', 'SHIPMENT_LINE')->pluck('label_idx');
        if ($ids->isNotEmpty()) {
            DB::table('label_print_log')->whereIn('label_idx', $ids)->delete();
            DB::table('barcode_label')->whereIn('label_idx', $ids)->delete();
        }
        if (Schema::hasColumn('barcode_label', 'shipment_line_idx')) {
            Schema::table('barcode_label', function ($table) {
                $table->dropForeign(['shipment_line_idx']);
                $table->dropColumn('shipment_line_idx');
            });
        }
        DB::statement("ALTER TABLE barcode_label MODIFY target ENUM('LOT') NOT NULL DEFAULT 'LOT' COMMENT '대상 ( LOT — 자재식별표 · 현품표 )'");
    }

    public function down(): void {
        DB::statement("ALTER TABLE barcode_label MODIFY target ENUM('LOT','SHIPMENT_LINE') NOT NULL COMMENT '대상'");
        Schema::table('barcode_label', function ($table) {
            $table->foreignId('shipment_line_idx')->nullable()->after('lot_idx')->comment('출하 줄')->constrained(table: 'shipment_line', column: 'shipment_line_idx')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE label_template MODIFY target ENUM('LOT','SHIPMENT','PRODUCT') NOT NULL");
        DB::table('label_template')->where('target', 'PRODUCT')->update(['target' => 'SHIPMENT']);
        DB::statement("ALTER TABLE label_template MODIFY target ENUM('LOT','SHIPMENT') NOT NULL");
    }
};

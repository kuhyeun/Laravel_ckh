<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 수주 자재 ( docs/대체자재-수주자재-설계.md §4 ) — 수주 줄마다 품목 BOM 을 복사해 그 수주에 맞게 고친다
//   bom.owner_type : ITEM( 품목 BOM — BOM관리 ) · ORDER_LINE( 수주 줄 자재 — 목록에 안 보임 · is_active N )
//   bom_line.source_bom_line_idx : 복사해 온 원본 줄 ( 이력 · 비교 )
//   수주 줄 자재의 투입 지점 = 그 수주 줄의 수주 공정( 라우팅 ③ ) 줄 — 품목 BOM 의 투입 지점( 품목 공정 줄 )을 원본으로 둔 줄로 바꾼다
//   bom_change_log : 수주 자재 변경 이력
//   이관 : 기존 수주 줄마다 수주 자재를 만든다
return new class extends Migration {
    public function up(): void {
        Schema::table('bom', function (Blueprint $table) {
            $table->enum('owner_type', ['ITEM', 'ORDER_LINE'])->default('ITEM')->after('bom_idx')->comment('ITEM 품목 BOM · ORDER_LINE 수주 줄 자재');
            $table->foreignId('sales_order_line_idx')->nullable()->unique()->after('owner_type')->comment('수주 줄 자재의 수주 줄 ( 줄을 지우면 같이 )')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->cascadeOnDelete();
            $table->foreignId('base_bom_idx')->nullable()->after('sales_order_line_idx')->comment('복사해 온 원본 BOM')
                ->constrained(table: 'bom', column: 'bom_idx')->nullOnDelete();
            $table->index('owner_type');
        });
        Schema::table('bom_line', function (Blueprint $table) {
            $table->unsignedBigInteger('source_bom_line_idx')->nullable()->after('bom_idx')->comment('복사해 온 원본 줄 ( 화면에서 더한 자재면 NULL )');
        });
        Schema::create('bom_change_log', function (Blueprint $table) {
            $table->id('log_idx')->comment('이력 IDX');
            $table->enum('scope', ['ORDER_LINE'])->comment('대상 구분');
            $table->unsignedBigInteger('ref_idx')->comment('sales_order_line_idx');
            $table->unsignedBigInteger('bom_idx')->nullable()->comment('대상 BOM');
            $table->enum('action', ['CREATE', 'RELOAD', 'ADD', 'REMOVE', 'UPDATE', 'SUB_ADD', 'SUB_REMOVE', 'SUB_UPDATE'])
                ->comment('CREATE 복사로 생김 · RELOAD 원본에서 다시 · ADD · REMOVE · UPDATE 자재 줄 · SUB_* 대체 자재');
            $table->unsignedBigInteger('child_item_idx')->nullable()->comment('대상 자재 ( 대체 자재 이력이면 그 대체 자재 )');
            $table->unsignedBigInteger('main_item_idx')->nullable()->comment('대체 자재 이력의 주 자재');
            $table->json('before_json')->nullable()->comment('바뀌기 전 값 ( CREATE · RELOAD 는 자재 목록 )');
            $table->json('after_json')->nullable()->comment('바뀐 뒤 값');
            $table->integer('create_account_idx')->comment('변경한 사람');
            $table->timestamp('create_datetime')->useCurrent()->comment('변경 시각');
            $table->index(['scope', 'ref_idx', 'log_idx']);
            $table->comment('수주 자재 변경 이력');
        });

        $this->migrateOrderLines();
    }

    public function down(): void {
        DB::table('bom')->where('owner_type', 'ORDER_LINE')->delete();   // 줄 · 대체 자재는 cascade
        Schema::dropIfExists('bom_change_log');
        Schema::table('bom_line', fn (Blueprint $table) => $table->dropColumn('source_bom_line_idx'));
        Schema::table('bom', function (Blueprint $table) {
            $table->dropForeign(['sales_order_line_idx']);
            $table->dropForeign(['base_bom_idx']);
            $table->dropIndex(['owner_type']);
            $table->dropUnique(['sales_order_line_idx']);
            $table->dropColumn(['owner_type', 'sales_order_line_idx', 'base_bom_idx']);
        });
    }

    private function migrateOrderLines(): void {
        $now   = now();
        $lines = DB::table('sales_order_line')->get(['sales_order_line_idx', 'item_idx']);

        foreach ($lines as $l) {
            $base = DB::table('bom')->where('owner_type', 'ITEM')->where('item_idx', $l->item_idx)->where('is_active', 'Y')->first();
            $bomIdx = DB::table('bom')->insertGetId([
                'owner_type'           => 'ORDER_LINE',
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'base_bom_idx'         => $base?->bom_idx,
                'item_idx'             => $l->item_idx,
                'version'              => 'SO-' . $l->sales_order_line_idx,
                'is_active'            => 'N',
                'remark'               => '수주 자재',
                'create_account_idx'   => 0,
                'create_datetime'      => $now,
            ]);

            // 투입 지점 : 품목 공정 줄 → 그 줄을 원본으로 둔 수주 공정 줄
            $orderRouting = DB::table('routing')->where('owner_type', 'ORDER_LINE')->where('sales_order_line_idx', $l->sales_order_line_idx)->value('routing_idx');
            $rlMap = $orderRouting ? DB::table('routing_line')->where('routing_idx', $orderRouting)->whereNotNull('source_routing_line_idx')
                ->pluck('routing_line_idx', 'source_routing_line_idx') : collect();

            $summary = [];
            foreach ($base ? DB::table('bom_line')->where('bom_idx', $base->bom_idx)->orderBy('seq')->get() : [] as $bl) {
                $row = (array) $bl;
                $srcIdx = $bl->bom_line_idx;
                unset($row['bom_line_idx'], $row['update_account_idx'], $row['update_datetime']);
                $row['bom_idx']             = $bomIdx;
                $row['source_bom_line_idx'] = $srcIdx;
                $row['routing_line_idx']    = $bl->routing_line_idx ? ($rlMap[$bl->routing_line_idx] ?? null) : null;
                $row['create_account_idx']  = 0;
                $row['create_datetime']     = $now;
                $newIdx = DB::table('bom_line')->insertGetId($row);

                foreach (DB::table('bom_line_substitute')->where('bom_line_idx', $srcIdx)->orderBy('seq')->get() as $s) {
                    $sub = (array) $s;
                    unset($sub['bom_line_substitute_idx'], $sub['update_account_idx'], $sub['update_datetime']);
                    $sub['bom_line_idx']       = $newIdx;
                    $sub['create_account_idx'] = 0;
                    $sub['create_datetime']    = $now;
                    DB::table('bom_line_substitute')->insert($sub);
                }
                $summary[] = ['child_item_idx' => (int) $bl->child_item_idx, 'qty' => (float) $bl->qty];
            }

            DB::table('bom_change_log')->insert([
                'scope'              => 'ORDER_LINE',
                'ref_idx'            => $l->sales_order_line_idx,
                'bom_idx'            => $bomIdx,
                'action'             => 'CREATE',
                'after_json'         => json_encode(['base_bom_idx' => $base?->bom_idx, 'lines' => $summary]),
                'create_account_idx' => 0,
                'create_datetime'    => $now,
            ]);
        }
    }
};

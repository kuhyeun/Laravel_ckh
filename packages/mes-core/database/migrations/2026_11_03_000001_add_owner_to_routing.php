<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 공정 라우팅 3단계 ( docs/라우팅-3단계-설계.md )
//   routing.owner_type : TEMPLATE( ① 공용 틀 ) · ITEM( ② 품목 전용 ) · ORDER_LINE( ③ 수주 줄 전용 )
//   routing_line.source_routing_line_idx : 복사해 온 원본 줄 ( BOM 투입 지점을 ② → ③ → 작업지시로 잇는다 )
//   routing_change_log : 품목 · 수주 · 작업지시 공정 변경 이력
//   이관 : 라우팅을 쓰는 제품 품목마다 틀을 ② 로 복사 → BOM 투입 지점 · 작업지시 공정 참조 · item.routing_idx 를 ② 로
//     ( 작업지시까지 옮기지 않으면 진행 중인 지시를 다시 저장할 때 자재 투입 지점이 끊긴다 )
//   ③ 이관은 2단계( 수주 ) 마이그레이션에서
return new class extends Migration {
    public function up(): void {
        Schema::table('routing', function (Blueprint $table) {
            $table->enum('owner_type', ['TEMPLATE', 'ITEM', 'ORDER_LINE'])->default('TEMPLATE')->after('routing_idx')
                ->comment('단계 : TEMPLATE 공용 틀 · ITEM 품목 전용 · ORDER_LINE 수주 줄 전용');
            $table->foreignId('item_idx')->nullable()->unique()->after('owner_type')->comment('품목 전용 라우팅의 품목')
                ->constrained(table: 'item', column: 'item_idx');
            $table->foreignId('sales_order_line_idx')->nullable()->unique()->after('item_idx')->comment('수주 줄 전용 라우팅의 수주 줄 ( 줄을 지우면 같이 )')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->cascadeOnDelete();
            $table->foreignId('base_routing_idx')->nullable()->after('sales_order_line_idx')->comment('복사해 온 원본 라우팅 ( 품목 ← 틀 · 수주 ← 품목 )')
                ->constrained(table: 'routing', column: 'routing_idx')->nullOnDelete();
            $table->index('owner_type');
        });

        Schema::table('routing_line', function (Blueprint $table) {
            // FK 없음 — 원본 줄이 지워져도 남는다 ( 비교 · 이력용 )
            $table->unsignedBigInteger('source_routing_line_idx')->nullable()->after('routing_idx')
                ->comment('복사해 온 원본 줄 ( 화면에서 더한 공정이면 NULL )');
            $table->index('source_routing_line_idx');
        });

        Schema::create('routing_change_log', function (Blueprint $table) {
            $table->id('log_idx')->comment('이력 IDX');
            $table->enum('scope', ['ITEM', 'ORDER_LINE', 'WORK_ORDER'])->comment('대상 구분');
            $table->unsignedBigInteger('ref_idx')->comment('item_idx · sales_order_line_idx · work_order_idx');
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('대상 라우팅 ( 작업지시면 NULL )');
            $table->enum('action', ['CREATE', 'ADD', 'REMOVE', 'MOVE', 'UPDATE', 'RELOAD'])
                ->comment('CREATE 복사로 생김 · ADD · REMOVE · MOVE 순서 · UPDATE 값 · RELOAD 원본에서 다시 불러옴');
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

        $this->migrateItems();
    }

    public function down(): void {
        // ② 참조를 원래 틀 줄로 되돌린다 ( 화면에서 더한 줄은 원본이 없어 비워진다 )
        $own = DB::table('routing')->where('owner_type', 'ITEM')->get();
        foreach ($own as $r) {
            $back = DB::table('routing_line')->where('routing_idx', $r->routing_idx)->pluck('source_routing_line_idx', 'routing_line_idx');
            foreach ($back as $lineIdx => $sourceIdx) {
                $exists = $sourceIdx && DB::table('routing_line')->where('routing_line_idx', $sourceIdx)->exists();
                DB::table('bom_line')->where('routing_line_idx', $lineIdx)->update(['routing_line_idx' => $exists ? $sourceIdx : null]);
                DB::table('work_order_op')->where('routing_line_idx', $lineIdx)->update(['routing_line_idx' => $exists ? $sourceIdx : null]);
            }
            DB::table('work_order')->where('routing_idx', $r->routing_idx)->update(['routing_idx' => $r->base_routing_idx]);
            DB::table('item')->where('routing_idx', $r->routing_idx)->update(['routing_idx' => $r->base_routing_idx]);
        }
        DB::table('routing')->where('owner_type', '!=', 'TEMPLATE')->delete();   // 줄은 cascade

        Schema::dropIfExists('routing_change_log');
        Schema::table('routing_line', function (Blueprint $table) {
            $table->dropIndex(['source_routing_line_idx']);
            $table->dropColumn('source_routing_line_idx');
        });
        Schema::table('routing', function (Blueprint $table) {
            $table->dropForeign(['item_idx']);
            $table->dropForeign(['sales_order_line_idx']);
            $table->dropForeign(['base_routing_idx']);
            $table->dropIndex(['owner_type']);
            $table->dropUnique(['item_idx']);
            $table->dropUnique(['sales_order_line_idx']);
            $table->dropColumn(['owner_type', 'item_idx', 'sales_order_line_idx', 'base_routing_idx']);
        });
    }

    // 라우팅을 쓰는 제품 품목마다 ② 를 만든다
    private function migrateItems(): void {
        $items = DB::table('item')->where('item_type', 'PRODUCT')->whereNotNull('routing_idx')->get(['item_idx', 'item_name', 'routing_idx', 'is_use']);
        $now   = now();

        foreach ($items as $item) {
            $template = DB::table('routing')->where('routing_idx', $item->routing_idx)->first();
            if (!$template) {
                continue;
            }

            $ownIdx = DB::table('routing')->insertGetId([
                'owner_type'         => 'ITEM',
                'item_idx'           => $item->item_idx,
                'base_routing_idx'   => $template->routing_idx,
                'routing_code'       => 'IT-' . $item->item_idx,
                'routing_name'       => mb_substr($item->item_name, 0, 60),
                'is_default'         => 'N',
                'is_use'             => $item->is_use,
                'create_account_idx' => 0,
                'create_datetime'    => $now,
            ]);

            $map     = [];   // 틀 줄 → ② 줄
            $summary = [];
            $lines   = DB::table('routing_line')->where('routing_idx', $template->routing_idx)->orderBy('seq')->get();
            foreach ($lines as $l) {
                $row = (array) $l;
                unset($row['routing_line_idx'], $row['update_account_idx'], $row['update_datetime']);
                $row['routing_idx']             = $ownIdx;
                $row['source_routing_line_idx'] = $l->routing_line_idx;
                $row['create_account_idx']      = 0;
                $row['create_datetime']         = $now;
                $map[$l->routing_line_idx] = DB::table('routing_line')->insertGetId($row);
                $summary[] = ['seq' => (int) $l->seq, 'process_idx' => (int) $l->process_idx];
            }

            // 그 품목 BOM 의 투입 지점
            $bomIdxs = DB::table('bom')->where('item_idx', $item->item_idx)->pluck('bom_idx');
            // 그 품목 작업지시의 공정 참조
            $woIdxs  = DB::table('work_order')->where('item_idx', $item->item_idx)->pluck('work_order_idx');
            foreach ($map as $from => $to) {
                DB::table('bom_line')->whereIn('bom_idx', $bomIdxs)->where('routing_line_idx', $from)->update(['routing_line_idx' => $to]);
                DB::table('work_order_op')->whereIn('work_order_idx', $woIdxs)->where('routing_line_idx', $from)->update(['routing_line_idx' => $to]);
            }
            DB::table('work_order')->whereIn('work_order_idx', $woIdxs)->where('routing_idx', $template->routing_idx)->update(['routing_idx' => $ownIdx]);
            DB::table('item')->where('item_idx', $item->item_idx)->update(['routing_idx' => $ownIdx]);

            DB::table('routing_change_log')->insert([
                'scope'              => 'ITEM',
                'ref_idx'            => $item->item_idx,
                'routing_idx'        => $ownIdx,
                'action'             => 'CREATE',
                'after_json'         => json_encode(['base_routing_idx' => $template->routing_idx, 'lines' => $summary]),
                'create_account_idx' => 0,
                'create_datetime'    => $now,
            ]);
        }
    }
};

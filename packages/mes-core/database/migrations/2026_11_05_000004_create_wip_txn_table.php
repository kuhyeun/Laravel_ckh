<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Services\WipService;

// 재공 이력 ( 자재관리 > 재공관리 3010 ) — 공정 실적마다 생산( + ) · 다음 공정 투입( − ) 줄 / WipService
//   만들 때 지금까지의 실적( 취소 안 된 것 )으로 채운다 · 메뉴 3010 을 화면에 연결
return new class extends Migration {

    public function up(): void {
        Schema::create('wip_txn', function (Blueprint $table) {
            $table->id('wip_txn_idx')->comment('재공 이력 IDX');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시( 품목 줄 )');
            $table->unsignedBigInteger('work_order_op_idx')->comment('재공이 있는 공정');
            $table->unsignedBigInteger('item_idx')->comment('품목 ( 지시 품목 )');
            $table->unsignedBigInteger('run_idx')->nullable()->comment('원인 실적');
            $table->enum('kind', ['MAKE', 'FEED'])->comment('MAKE 생산( + ) / FEED 다음 공정 투입( − )');
            $table->enum('is_reversal', ['Y', 'N'])->default('N')->comment('실적 취소로 생긴 반대 줄');
            $table->decimal('qty', 18, 6)->comment('수량 ( + 늘어남 / − 줄어듦 )');
            $table->unsignedBigInteger('to_op_idx')->nullable()->comment('FEED : 가져간 공정');
            $table->dateTime('occurred_at')->comment('일시 ( 실적 작업 일시 · 취소 줄은 취소 일시 )');
            $table->integer('operator_idx')->nullable()->comment('작업자 ( 원인 실적 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index(['work_order_idx', 'work_order_op_idx']);
            $table->index('occurred_at');
            $table->index('run_idx');
            $table->comment('재공 이력 ( 공정 사이 반제품 — 현재 재공 = 지시 × 공정 합계 )');
        });

        // 지금까지의 실적으로 채우기
        $svc = app(WipService::class);
        $woIdxs = WorkOrderOpRun::query()->join('work_order_op as op', 'op.work_order_op_idx', '=', 'work_order_op_run.work_order_op_idx')
            ->where('work_order_op_run.is_canceled', 'N')->distinct()->pluck('op.work_order_idx');
        foreach ($woIdxs as $woIdx) {
            $wo  = WorkOrder::find($woIdx);
            $ops = WorkOrderOp::with('process')->where('work_order_idx', $woIdx)->orderBy('seq')->get();
            $runs = WorkOrderOpRun::whereIn('work_order_op_idx', $ops->pluck('work_order_op_idx'))->where('is_canceled', 'N')
                ->orderBy('worked_at')->orderBy('run_idx')->get();
            foreach ($runs as $run) {
                $svc->recordRun($wo, $ops, $ops->firstWhere('work_order_op_idx', $run->work_order_op_idx), $run);
            }
        }
        DB::table('wip_txn')->where('create_account_idx', 0)->update(['create_account_idx' => 1]);   // 이관 줄 등록자 = 시스템

        DB::table('system_menu')->where('menu_code', '3010')->update([
            'menu_name' => '재공관리', 'url_path' => '/material/wip', 'page_component' => 'Production/WipManage', 'read_only' => 'Y',
        ]);
    }

    public function down(): void {
        DB::table('system_menu')->where('menu_code', '3010')->update([
            'menu_name' => '재공관리 ( 미개발 )', 'url_path' => null, 'page_component' => null, 'read_only' => 'N',
        ]);
        Schema::dropIfExists('wip_txn');
    }
};

<?php

namespace MesCore\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 업무 데이터 초기화 — 시스템설정 · 메뉴 · 계정 · 기준정보는 남기고 업무 전표만 비운다
 *
 *   php artisan mes:reset-transactions           무엇을 몇 건 지울지 보여 주기만 ( 시험 실행 )
 *   php artisan mes:reset-transactions --force   실제로 지운다 ( 되돌릴 수 없음 · production 에서는 거부 )
 *   … --with-routing-bom                          라우팅 · BOM 도 전부 비운다 ( 공용 틀 · 품목 · 수주 사본 모두 + 품목의 기본 라우팅 연결 해제 )
 *                                                 품목 · 공정 · 거래처 등 나머지 기준정보는 그대로 ( 라우팅 · BOM 을 처음부터 다시 잡을 때 )
 *
 *   비움( TRUNCATE — 번호도 1부터 ) : 수주 · 발주 · 작업지시 · 재공 · 외주 · 재고 · LOT · 출하 · 클레임 · 검사 · 라벨 발행 · 변경 이력
 *   일부만 : 라우팅 · BOM 은 수주 줄 사본( ORDER_LINE )만 ( --with-routing-bom 이면 전부 ) / 첨부는 업무 전표에 붙은 것만( 파일도 )
 *   남김 : 계정 · 권한 · 메뉴 · 정책 · 코드 · 계산식 · 그리드 설정 · 품목 · 거래처 · 공정 · 설비 · 창고 · 라우팅 틀 / 품목 라우팅 · 품목 BOM ( 옵션 없을 때 ) · 검사 기준 · 리드타임 · LOT 규칙 · 라벨 양식
 */
class ResetTransactionsCommand extends Command {

    protected $signature = 'mes:reset-transactions
        {--force : 실제로 지운다}
        {--with-routing-bom : 라우팅 · BOM 도 전부 비운다 ( 공용 틀 · 품목 라우팅 · 품목 BOM 포함 )}';

    protected $description = '업무 데이터 초기화 ( 기준정보 · 시스템설정 · 메뉴는 남김 )';

    // 통째로 비우는 테이블 ( 순서 무관 — 외래키 검사를 끄고 비운다 )
    private const TRUNCATE = [
        // 수주 · 발주
        'sales_order', 'sales_order_line',
        'purchase_order', 'purchase_order_line', 'purchase_order_line_source',
        // 생산계획 · 작업지시
        'production_plan', 'production_plan_source', 'work_order', 'work_order_source', 'work_order_op', 'work_order_op_run', 'work_order_material', 'work_order_sheet', 'wo_stock',
        // 재공 · 외주
        'wip_txn', 'subcon_issue', 'subcon_receipt',
        // 재고 · LOT
        'stock_txn', 'stock_txn_line', 'stock_ledger', 'stock_balance', 'lot', 'lot_seq',
        // 문서 번호 순번 ( Support/DocNumber )
        'doc_seq',
        // 출하
        'shipment', 'shipment_line', 'shipment_pick',
        // 클레임
        'claim', 'claim_line',
        // 검사 · 불량
        'inspection', 'defect',
        // 라벨 발행
        'barcode_label', 'label_print_log',
        // 변경 이력 ( 수주 사본 · 기준정보 변경 모두 — 새로 시작 )
        'routing_change_log', 'bom_change_log',
    ];

    // --with-routing-bom : 라우팅 · BOM 전부 ( 단계 구분 없이 — 변경 이력은 TRUNCATE 에서 이미 비움 )
    //   이 테이블을 가리키는 곳 : item.routing_idx ( 기본 라우팅 → NULL 로 ) · 작업지시 쪽은 위에서 같이 비워진다
    private const ROUTING_BOM = ['routing', 'routing_line', 'bom', 'bom_line', 'bom_line_substitute'];

    // 업무 전표에 붙은 첨부의 ref_type ( CoreServiceProvider 의 AttachmentService 등록 — 기준정보 item · partner 는 남긴다 )
    //   업체 모듈이 업무 전표 첨부를 더 붙이면 여기에도 더한다
    private const TX_ATTACH_REFS = ['sales_order', 'purchase_order', 'receipt_line', 'work_order', 'shipment_line', 'claim_line'];

    public function handle(): int {
        $force       = (bool) $this->option('force');
        $withRouting = (bool) $this->option('with-routing-bom');
        if ($force && app()->environment('production')) {
            $this->error('운영 환경에서는 실행할 수 없습니다.');
            return self::FAILURE;
        }

        $plan = $this->plan($withRouting);
        $this->table(['대상', '지울 건수'], array_map(fn ($k, $v) => [$k, number_format($v)], array_keys($plan), $plan));
        if ($withRouting) {
            $this->warn('라우팅 · BOM 을 공용 틀 · 품목 것까지 전부 지웁니다. 품목의 기본 라우팅 연결도 비워집니다.');
        }

        if (!$force) {
            $this->info('시험 실행입니다. 실제로 지우려면 --force 를 붙이세요.');
            return self::SUCCESS;
        }
        if (!$this->confirm('위 데이터를 지웁니다. 되돌릴 수 없습니다. 계속할까요?', false)) {
            $this->line('취소했습니다.');
            return self::SUCCESS;
        }

        $files = $this->txAttachments()->get(['storage_disk', 'storage_path']);

        Schema::disableForeignKeyConstraints();
        try {
            if ($withRouting) {
                // 라우팅 · BOM 전부 ( 번호도 1부터 ) + 품목의 기본 라우팅 연결 해제
                DB::table('item')->whereNotNull('routing_idx')->update(['routing_idx' => null]);
                foreach (self::ROUTING_BOM as $t) {
                    DB::table($t)->truncate();
                }
            } else {
                // 수주 줄 사본만 ( 라우팅 ③ · 수주 자재 )
                $routings = DB::table('routing')->where('owner_type', 'ORDER_LINE')->pluck('routing_idx');
                DB::table('routing_line')->whereIn('routing_idx', $routings)->delete();
                DB::table('routing')->whereIn('routing_idx', $routings)->delete();

                $boms  = DB::table('bom')->where('owner_type', 'ORDER_LINE')->pluck('bom_idx');
                $lines = DB::table('bom_line')->whereIn('bom_idx', $boms)->pluck('bom_line_idx');
                DB::table('bom_line_substitute')->whereIn('bom_line_idx', $lines)->delete();
                DB::table('bom_line')->whereIn('bom_idx', $boms)->delete();
                DB::table('bom')->whereIn('bom_idx', $boms)->delete();
            }

            $this->txAttachments()->delete();

            foreach (self::TRUNCATE as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // 첨부 파일 ( DB 를 다 지운 뒤 — 못 지운 파일은 알리기만 )
        $missed = 0;
        foreach ($files as $f) {
            try {
                Storage::disk($f->storage_disk)->delete($f->storage_path) || $missed++;
            } catch (\Throwable $e) {
                $missed++;
            }
        }

        $this->info('업무 데이터를 지웠습니다.' . ($missed ? " ( 첨부 파일 {$missed}개는 지우지 못했습니다 — 저장소에서 직접 확인 )" : ''));
        $this->line('화면에 남은 값이 보이면 php artisan cache:clear 를 실행하세요.');
        return self::SUCCESS;
    }

    // 지울 건수 ( 시험 실행 · 실행 전 확인 )
    private function plan(bool $withRouting): array {
        $plan = [];
        foreach (self::TRUNCATE as $t) {
            if (Schema::hasTable($t)) {
                $plan[$t] = DB::table($t)->count();
            }
        }
        if ($withRouting) {
            foreach (self::ROUTING_BOM as $t) {
                $plan[$t . ' ( 전부 )'] = DB::table($t)->count();
            }
            $plan['item.routing_idx ( 기본 라우팅 연결 해제 )'] = DB::table('item')->whereNotNull('routing_idx')->count();
            $plan['attachment ( 업무 전표 )'] = $this->txAttachments()->count();
            return $plan;
        }
        $routings = DB::table('routing')->where('owner_type', 'ORDER_LINE')->pluck('routing_idx');
        $boms     = DB::table('bom')->where('owner_type', 'ORDER_LINE')->pluck('bom_idx');
        $plan['routing ( 수주 사본 )']      = $routings->count();
        $plan['routing_line ( 수주 사본 )'] = DB::table('routing_line')->whereIn('routing_idx', $routings)->count();
        $plan['bom ( 수주 자재 )']          = $boms->count();
        $plan['bom_line ( 수주 자재 )']     = DB::table('bom_line')->whereIn('bom_idx', $boms)->count();
        $plan['attachment ( 업무 전표 )']   = $this->txAttachments()->count();
        return $plan;
    }

    private function txAttachments() {
        return DB::table('attachment')->whereIn('ref_type', self::TX_ATTACH_REFS);
    }
}

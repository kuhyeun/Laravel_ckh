<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Process;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Production\Services\WipService;
use Illuminate\Database\Eloquent\Builder;
use MesCore\Production\Models\WipTxn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 재공관리 ( 3010 · 자재관리 > 재공관리 ) — 조회만. 공정 사이 반제품 ( WipService )
//   재공 이력만 ( 현재 재공 합계 화면은 두지 않는다 — 11-05 )
//   재공 이력 : wip_txn 1줄 = 1줄 ( 생산 + / 다음 공정 투입 − / 취소 줄 )
class WipController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SORTABLE = ['occurred_at', 'wo_no'];

    protected function listTieBreaker(): ?string {
        return 't.wip_txn_idx';
    }

    // 재공 이력
    public function history(Request $request) {
        $v = $this->filters($request);
        $q = $this->joined(WipTxn::query()->from('wip_txn as t'), $v)
            ->leftJoin('work_order_op as top', 'top.work_order_op_idx', '=', 't.to_op_idx')
            ->leftJoin('process as tp', 'tp.process_idx', '=', 'top.process_idx')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 't.operator_idx')
            ->select([
                't.wip_txn_idx', 't.work_order_idx', 't.kind', 't.is_reversal', 't.qty', 't.occurred_at', 't.run_idx', 't.subcon_issue_idx',
                'wo.wo_no', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit',
                'op.seq', 'p.process_name', 'top.seq as to_seq', 'tp.process_name as to_process_name',
                DB::raw("COALESCE(NULLIF(u.user_name, ''), u.user_id) as operator_name"),
            ]);
        if (!empty($v['from'])) $q->where('t.occurred_at', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('t.occurred_at', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!$v['include_reversal']) {
            // 취소된 실적은 원래 줄 · 반대 줄 모두 숨김
            $q->where(fn ($w) => $w->whereNull('t.run_idx')->orWhereNotIn('t.run_idx', DB::table('wip_txn')->where('is_reversal', 'Y')->whereNotNull('run_idx')->select('run_idx')))
              ->where(fn ($w) => $w->whereNull('t.subcon_issue_idx')->orWhereNotIn('t.subcon_issue_idx', DB::table('wip_txn')->where('is_reversal', 'Y')->whereNotNull('subcon_issue_idx')->select('subcon_issue_idx')));
        }
        $q->addSelect(['wo.lot_mode', 'run.lot_idx as run_lot_idx'])
            ->leftJoin('work_order_op_run as run', 'run.run_idx', '=', 't.run_idx');
        $q->orderBy('t.occurred_at')->orderBy('t.wip_txn_idx');   // 먼저 일어난 것부터 위에서 아래로 ( 같은 실적이면 투입 − → 생산 + 순 — 11-05 )

        $lots = [];   // 실적 LOT 지시 줄의 LOT ( 그 페이지 줄만 한 번에 — lotsOf )
        return $this->listResponse($request, $q, self::SORTABLE, 'wip',
            function ($r) use (&$lots) { return $this->historyRow($r, $lots); },
            function ($rows) use (&$lots) { $lots = $this->lotsOf($rows); });
    }

    private function historyRow(object $r, array $lots): array {
        return [
            'wip_txn_idx'    => $r->wip_txn_idx,
            'work_order_idx' => $r->work_order_idx,
            'occurred_at'    => substr((string) $r->occurred_at, 0, 16),
            'wo_no'          => $r->wo_no,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'item_spec'      => $r->item_spec,
            'item_material'  => $r->item_material,
            'spec_unit'      => $r->spec_unit,
            'op_label'       => $this->opLabel($r->seq, $r->process_name),
            'kind'           => $r->kind,
            'kind_label'     => ($r->subcon_issue_idx ? '외주 출고' : (WipService::KINDS[$r->kind] ?? $r->kind)) . ($r->is_reversal === 'Y' ? ' 취소' : ''),
            'to_label'       => $r->to_seq ? $this->opLabel($r->to_seq, $r->to_process_name) : null,
            'is_reversal'    => $r->is_reversal === 'Y',
            'qty'            => (float) $r->qty,
            'operator_name'  => $r->operator_name,
            'lot_no'         => $this->lotOf($r, $lots),
        ];
    }

    // 줄의 LOT ( 실적 LOT 지시만 — 실적LOT-설계 §11-7 )
    //   생산 + : 그 실적이 받은 LOT · 다음 공정 투입 − : 그 실적에 연결한 앞 공정 LOT · 외주 출고 − : 내보낸 앞 공정 LOT
    private function lotOf(object $r, array $lots): ?string {
        if ($r->lot_mode !== 'RUN') {
            return null;
        }
        if ($r->subcon_issue_idx) {
            return $lots['sub'][$r->subcon_issue_idx] ?? null;
        }
        if (!$r->run_idx) {
            return null;
        }
        return $r->kind === 'MAKE' ? ($lots['run'][$r->run_lot_idx] ?? null) : ($lots['feed'][$r->run_idx] ?? null);
    }

    // 페이지 줄의 LOT 번호를 한 번에 ( 실적 LOT · 연결 LOT · 외주 출고 LOT )
    private function lotsOf($rows): array {
        $rows = collect($rows)->where('lot_mode', 'RUN');
        $out  = ['run' => [], 'feed' => [], 'sub' => []];
        if ($rows->isEmpty()) {
            return $out;
        }
        $runLots = $rows->where('kind', 'MAKE')->pluck('run_lot_idx')->filter()->unique();
        if ($runLots->isNotEmpty()) {
            $out['run'] = DB::table('lot')->whereIn('lot_idx', $runLots)->pluck('lot_no', 'lot_idx')->all();
        }
        $feedRuns = $rows->where('kind', 'FEED')->whereNull('subcon_issue_idx')->pluck('run_idx')->filter()->unique();
        if ($feedRuns->isNotEmpty()) {
            $out['feed'] = DB::table('work_order_run_input as x')->join('lot as l', 'l.lot_idx', '=', 'x.lot_idx')
                ->whereIn('x.run_idx', $feedRuns)->where('x.input_type', 'RUN')->where('x.is_canceled', 'N')
                ->orderBy('x.run_input_idx')->get(['x.run_idx', 'l.lot_no'])
                ->groupBy('run_idx')->map(fn ($g) => $g->pluck('lot_no')->unique()->implode(', '))->all();
        }
        $issues = $rows->pluck('subcon_issue_idx')->filter()->unique();
        if ($issues->isNotEmpty()) {
            $out['sub'] = DB::table('subcon_issue_lot as x')->join('lot as l', 'l.lot_idx', '=', 'x.lot_idx')
                ->whereIn('x.subcon_issue_idx', $issues)->where('x.input_type', 'RUN')
                ->get(['x.subcon_issue_idx', 'l.lot_no'])
                ->groupBy('subcon_issue_idx')->map(fn ($g) => $g->pluck('lot_no')->unique()->implode(', '))->all();
        }
        return $out;
    }

    // 선택지 : 공정( 수량 공정 ) · 공정실적관리 주소( 권한이 있을 때 — 행 클릭으로 이동 )
    public function formData(MenuPermissionService $permissions) {
        return response()->json([
            'op_result_url' => $permissions->canPageForMe('Production/OpResult', 'can_read')
                ? Menu::where('page_component', 'Production/OpResult')->orderByRaw("read_only = 'N' DESC")->value('url_path') : null,
            // LOT 열 : 실적 LOT 지시가 하나라도 있으면 ( 설정을 끈 뒤 남은 지시도 보이게 )
            'show_lot'      => DB::table('work_order')->where('lot_mode', 'RUN')->exists(),
            'processes'     => Process::where('is_use', 'Y')->orderBy('process_code')->get(['process_idx', 'process_code', 'process_name', 'process_type', 'is_system', 'system_key'])
                ->filter(fn (Process $p) => $p->isQtyProcess())
                ->map(fn (Process $p) => ['value' => $p->process_idx, 'label' => $p->process_name])->values(),
        ]);
    }

    // ---- 내부 ----

    private function filters(Request $request): array {
        $v = $request->validate([
            'from'             => 'nullable|date_format:Y-m-d',
            'to'               => 'nullable|date_format:Y-m-d',
            'keyword'          => 'nullable|string|max:100',
            'search_field'     => 'nullable|string|max:30',
            'process_idx'      => 'nullable|integer',
            'include_reversal' => 'nullable|boolean',
        ]);
        $v['include_reversal'] = filter_var($v['include_reversal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $v;
    }

    // 이력 + 지시 · 공정 · 품목 ( 검색어 · 공정 )
    private function joined(Builder $q, array $v): Builder {
        $q->join('work_order as wo', 'wo.work_order_idx', '=', 't.work_order_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 't.work_order_op_idx')
            ->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->join('item as i', 'i.item_idx', '=', 't.item_idx');
        if (!empty($v['process_idx'])) $q->where('op.process_idx', $v['process_idx']);
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('wo.wo_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
        ]);
        return $q;
    }

    // 공정 이름만 ( 순번 030 은 붙이지 않는다 — 11-05 )
    private function opLabel($seq, $name): string {
        return (string) $name;
    }
}

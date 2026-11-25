<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderMaterial;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Models\WorkOrderRunInput;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 외주 건 자재 출고 ( 자재관리 > 출고관리 [외주] · 11-23 · docs/외주발주-설계.md )
 *
 *   외주 건 = 외주 발주 줄( ORDER · 취소 안 된 발주 ) 또는 발주 없이 나간 외주 출고( ISSUE · 취소 안 됨 ) — 대기 · 진행 중 지시만
 *   자재 : 그 외주 공정에 붙은 지시 자재( work_order_op_idx ) + 이 건으로 이미 낸 자재 + 화면에서 더한 자재
 *          지시에 없는 자재는 그 외주 공정의 BOM 외 줄로 더한다 ( 정책 상관없이 — 사급 자재 등 )
 *   수량 : 소요 계산 없이 사람이 넣는다 · LOT · 창고 = 재고 잔고에서 고른다
 *   issueBalance() : 재고관리 LOT 창 [출고 → 외주] — 그 LOT · 창고에서 수량만큼 이 외주 건으로 ( 자재 줄은 addMaterial 과 같이 )
 *   LOT 연결 ( 11-24 · 공정 LOT 추적 지시 ) : 외주 입고로 생긴 실적 LOT ← 그 외주 건으로 나간 자재 LOT ( 투입 · 수량 없음 )
 *     입고 때( linkRun ) · 입고 뒤 자재를 더 내면( issue ) 이미 생긴 입고 LOT 에도 · 자재 출고를 취소하면 그 LOT 이 이 건에 더 없을 때 연결 해제
 *     외주 건 = 발주 줄( 그 줄로 나간 외주 출고들의 입고 ) · 발주 없는 외주 출고( 그 출고의 입고 )
 *   출고 · 취소는 자재출고와 같은 기계 ( MaterialIssueService::issue · cancel ) — 전표에 외주 건( subcon_order_line_idx / subcon_issue_idx )을 남긴다
 */
class SubconMaterialService {
    public const KINDS = ['ORDER', 'ISSUE'];

    public function __construct(private MaterialIssueService $issues) {}

    // 외주 건 목록 ( 검색어 : 발주 · 출고번호 · 지시번호 · 품번 · 품명 · 외주처 )
    public function cases(?string $keyword = null): Collection {
        $kw = $keyword !== null && trim($keyword) !== '' ? '%' . trim($keyword) . '%' : null;
        $match = fn ($q, string $no) => $kw ? $q->where(fn ($w) => $w->where($no, 'like', $kw)->orWhere('w.wo_no', 'like', $kw)
            ->orWhere('i.item_code', 'like', $kw)->orWhere('i.item_name', 'like', $kw)->orWhere('p.partner_name', 'like', $kw)) : $q;

        $orders = $match(DB::table('subcon_order_line as l')
            ->join('subcon_order as o', 'o.subcon_order_idx', '=', 'l.subcon_order_idx')
            ->join('work_order as w', 'w.work_order_idx', '=', 'l.work_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'l.work_order_op_idx')
            ->join('process as pr', 'pr.process_idx', '=', 'op.process_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
            ->where('o.status', '!=', 'CANCELED')->whereIn('w.status', ['WAIT', 'RUN']), 'o.order_no')
            ->orderByDesc('o.order_date')->orderByDesc('l.subcon_order_line_idx')->limit(300)
            ->get(['l.subcon_order_line_idx as ref_idx', 'o.order_no as ref_no', 'o.order_date as ref_date', 'l.qty', 'p.partner_name',
                'w.work_order_idx', 'w.wo_no', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'l.work_order_op_idx', 'pr.process_name'])
            ->map(fn ($r) => ['kind' => 'ORDER'] + (array) $r);

        $issues = $match(DB::table('subcon_issue as s')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 's.work_order_op_idx')
            ->join('work_order as w', 'w.work_order_idx', '=', 'op.work_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
            ->join('process as pr', 'pr.process_idx', '=', 'op.process_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 's.partner_idx')
            ->whereNull('s.subcon_order_line_idx')->where('s.is_canceled', 'N')->whereIn('w.status', ['WAIT', 'RUN']), 's.issue_no')
            ->orderByDesc('s.issued_at')->orderByDesc('s.subcon_issue_idx')->limit(300)
            ->get(['s.subcon_issue_idx as ref_idx', 's.issue_no as ref_no', 's.issued_at as ref_date', 's.qty', 'p.partner_name',
                'w.work_order_idx', 'w.wo_no', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 's.work_order_op_idx', 'pr.process_name'])
            ->map(fn ($r) => ['kind' => 'ISSUE'] + (array) $r);

        $rows = $orders->concat($issues);
        // 이 건으로 낸 자재 줄 수 ( 취소 안 된 )
        $sent = fn (string $col, array $ids) => $ids ? DB::table('stock_txn as t')->join('stock_txn_line as tl', 'tl.stock_txn_idx', '=', 't.stock_txn_idx')
            ->whereIn("t.{$col}", $ids)->where('t.txn_type', MaterialIssueService::TXN_TYPE)->where('tl.line_status', 'DONE')
            ->groupBy("t.{$col}")->selectRaw("t.{$col} as k, COUNT(*) as n")->pluck('n', 'k') : collect();
        $byOrder = $sent('subcon_order_line_idx', $orders->pluck('ref_idx')->all());
        $byIssue = $sent('subcon_issue_idx', $issues->pluck('ref_idx')->all());
        return $rows->map(fn ($r) => $r + [
            'key'      => "{$r['kind']}:{$r['ref_idx']}",
            'ref_date' => substr((string) $r['ref_date'], 0, $r['kind'] === 'ISSUE' ? 16 : 10),
            'qty'      => (float) $r['qty'],
            'sent_count' => (int) (($r['kind'] === 'ORDER' ? $byOrder : $byIssue)[$r['ref_idx']] ?? 0),
        ])->values();
    }

    // 외주 건 → [ 지시, 외주 공정, 전표 칸, 머리 ]
    public function resolve(string $kind, int $refIdx): array {
        if ($kind === 'ORDER') {
            $r = DB::table('subcon_order_line as l')->join('subcon_order as o', 'o.subcon_order_idx', '=', 'l.subcon_order_idx')
                ->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
                ->where('l.subcon_order_line_idx', $refIdx)->first(['l.work_order_op_idx', 'l.qty', 'o.order_no as ref_no', 'o.status', 'p.partner_name']);
            $bad = !$r || $r->status === 'CANCELED';
            $col = 'subcon_order_line_idx';
        } else {
            $r = DB::table('subcon_issue as s')->leftJoin('partner as p', 'p.partner_idx', '=', 's.partner_idx')
                ->where('s.subcon_issue_idx', $refIdx)->first(['s.work_order_op_idx', 's.qty', 's.issue_no as ref_no', 's.is_canceled', 's.subcon_order_line_idx', 'p.partner_name']);
            $bad = !$r || $r->is_canceled === 'Y' || $r->subcon_order_line_idx;   // 발주로 나간 출고는 발주 줄로
            $col = 'subcon_issue_idx';
        }
        if ($bad) {
            throw ValidationException::withMessages(['ref_idx' => ['취소되었거나 없는 외주 건입니다. 새로고침하세요.']]);
        }
        $op = WorkOrderOp::with('process')->find($r->work_order_op_idx);
        $wo = $op ? WorkOrder::with('item')->find($op->work_order_idx) : null;
        if (!$op || !$wo) {
            throw ValidationException::withMessages(['ref_idx' => ['작업지시를 찾을 수 없습니다.']]);
        }
        return [$wo, $op, $col, [
            'kind' => $kind, 'ref_idx' => $refIdx, 'ref_no' => $r->ref_no, 'partner_name' => $r->partner_name, 'qty' => (float) $r->qty,
            'work_order_idx' => (int) $wo->work_order_idx, 'wo_no' => $wo->wo_no, 'wo_status' => $wo->status,
            'item_code' => $wo->item?->item_code, 'item_name' => $wo->item?->item_name, 'item_spec' => $wo->item?->spec, 'spec_unit' => $wo->item?->spec_unit,
            'work_order_op_idx' => (int) $op->work_order_op_idx, 'process_name' => $op->process?->process_name,
        ]];
    }

    // 이 건의 자재 · 후보 LOT · 출고 이력
    //   items ( 11-24 ) : 화면에서 고른 품목 — 지시에 있는 자재면 그 줄 · 없으면 아직 줄이 없는 「출고 전」 자재( pending · 출고할 때 줄을 만든다 )
    public function plan(string $kind, int $refIdx, array $items = []): array {
        [$wo, $op, $col, $head] = $this->resolve($kind, $refIdx);
        $history = $this->history($col, $refIdx);
        $sentBy  = $history->where('status', 'DONE')->groupBy('work_order_material_idx')->map(fn ($g) => (float) $g->sum('qty'));
        $items   = array_values(array_unique(array_map('intval', $items)));

        $all  = WorkOrderMaterial::with('childItem')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        $mats = $all->filter(fn (WorkOrderMaterial $m) => (int) $m->work_order_op_idx === (int) $op->work_order_op_idx
                || $sentBy->has($m->work_order_material_idx) || in_array((int) $m->child_item_idx, $items, true))
            ->values();
        $pendingIdx = array_values(array_diff($items, $all->pluck('child_item_idx')->map(fn ($x) => (int) $x)->all()));
        $pending = \MesCore\Config\Models\Item::whereIn('item_idx', $pendingIdx)->get()->sortBy(fn ($i) => array_search((int) $i->item_idx, $pendingIdx))->values();
        $lots = $this->issues->lotCandidates($mats->pluck('child_item_idx')->merge($pending->pluck('item_idx'))->unique()->values());

        return [
            'head'      => $head,
            'materials' => $mats->map(fn (WorkOrderMaterial $m) => [
                'work_order_material_idx' => (int) $m->work_order_material_idx,
                'item_idx'  => (int) $m->child_item_idx,
                'item_code' => $m->childItem?->item_code, 'item_name' => $m->childItem?->item_name, 'item_spec' => $m->childItem?->spec,
                'spec_unit' => $m->childItem?->spec_unit,
                'on_op'     => (int) $m->work_order_op_idx === (int) $op->work_order_op_idx,   // 외주 공정에 붙은 자재
                'is_extra'  => $m->is_extra === 'Y',
                'removable' => $m->is_extra === 'Y' && (float) $m->issued_qty <= 0.000001,   // 출고한 적 없는 추가 자재 줄 → ✕ 로 지운다
                'pending'   => false,
                'required_qty' => (float) $m->required_qty,          // 참고 ( 지시 소요 — 계산하지 않는다 )
                'issued_qty'   => (float) $m->issued_qty,            // 지시 전체 출고
                'sent_qty'     => (float) ($sentBy[$m->work_order_material_idx] ?? 0),   // 이 외주 건으로
                'lots'      => ($lots[$m->child_item_idx] ?? collect())->values()->all(),
            ])->concat($pending->map(fn ($i) => [
                'work_order_material_idx' => null, 'item_idx' => (int) $i->item_idx,
                'item_code' => $i->item_code, 'item_name' => $i->item_name, 'item_spec' => $i->spec, 'spec_unit' => $i->spec_unit,
                'on_op' => true, 'is_extra' => true, 'removable' => true, 'pending' => true,   // 아직 줄 없음 — 출고할 때 만든다
                'required_qty' => 0.0, 'issued_qty' => 0.0, 'sent_qty' => 0.0,
                'lots' => ($lots[$i->item_idx] ?? collect())->values()->all(),
            ]))->values()->all(),
            'history'   => $history->values()->all(),
            'bom'       => $this->bomList($wo, $op),   // 이 지시 품목의 BOM 전체 ( 11-25 · MES_1125 #12 — 자재 고르기 큰 창에 확인용 )
        ];
    }

    /**
     * 작업지시 품목의 BOM 전체 ( 11-25 · MES_1125 #12 ) — 자재 고르기 큰 창( ItemSearchModal )에 「이 작업지시 BOM」으로
     *   1단계 = 이 지시에 복사된 자재( work_order_material — 지시 때의 BOM · 대체 자재 · BOM 외 자재 포함 )
     *   그 아래 = 각 품목의 지금 사용 중 BOM ( BomService::treeData ) — 반제품 · 그 자재까지 ( 같은 품목은 한 갈래에 한 번 )
     *   줄 : depth · 품목 · 대분류 · 소분류 · 개당 / 1개로 · 대체 · BOM 외 · 이 외주 공정에 묶임( on_op )
     */
    public function bomList(WorkOrder $wo, WorkOrderOp $op): array {
        $mats  = WorkOrderMaterial::with('childItem')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        $boms  = app(\MesCore\Config\Services\BomService::class);
        $trees = [];
        $out   = [];
        $info  = fn ($it) => [
            'item_idx' => (int) $it['item_idx'], 'item_code' => $it['item_code'], 'item_name' => $it['item_name'], 'spec' => $it['spec'],
            'material' => $it['material'] ?? null, 'spec_unit' => $it['spec_unit'], 'item_type' => $it['item_type'], 'item_kind' => $it['item_kind'] ?? null,
        ];
        // 아래 단계 펼치기 ( 그 품목의 사용 중 BOM )
        $walk = function (int $itemIdx, int $depth, array $path) use (&$walk, &$trees, &$out, $boms, $info) {
            $tree = $trees[$itemIdx] ??= $boms->treeData($itemIdx);
            foreach ($tree['boms'][$itemIdx]['lines'] ?? [] as $l) {
                foreach ([['child_item_idx' => $l['child_item_idx'], 'qty' => $l['qty'], 'yield_qty' => $l['yield_qty'], 'sub' => false],
                          ...array_map(fn ($x) => $x + ['sub' => true], $l['substitutes'])] as $c) {
                    $child = (int) $c['child_item_idx'];
                    $it = $tree['items'][$child] ?? null;
                    if (!$it || isset($path[$child])) continue;
                    $out[] = $info($it) + ['depth' => $depth, 'qty_per' => $c['qty'], 'yield_qty' => $c['yield_qty'], 'is_substitute' => $c['sub'], 'is_extra' => false, 'on_op' => false];
                    if ($depth < 6) $walk($child, $depth + 1, $path + [$child => true]);
                }
            }
        };
        foreach ($mats as $m) {
            $it = $m->childItem;
            if (!$it) continue;
            $out[] = $info($it->toArray()) + [
                'depth' => 1, 'qty_per' => (float) $m->qty_per, 'yield_qty' => $m->yield_qty !== null ? (float) $m->yield_qty : null,
                'is_substitute' => $m->substitute_of_idx !== null, 'is_extra' => $m->is_extra === 'Y',
                'on_op' => (int) $m->work_order_op_idx === (int) $op->work_order_op_idx,
            ];
            $walk((int) $it->item_idx, 2, [(int) $wo->item_idx => true, (int) $it->item_idx => true]);
        }
        // 고를 수 있는 줄 : 지시에 있는 1단계 품목( 그 자재 줄 ) · 자재 — 그 밖의 반제품 · 제품은 확인용 ( 지시에 없는 제품은 출고 줄을 만들 수 없다 )
        return array_map(fn ($r) => $r + ['pickable' => $r['depth'] === 1 || $r['item_type'] === 'MATERIAL'], $out);
    }

    // 출고한 적 없는 추가 자재 줄 지우기 ( 11-24 · ✕ )
    public function removeMaterial(string $kind, int $refIdx, int $materialIdx): void {
        [$wo] = $this->resolve($kind, $refIdx);
        $this->issues->removeExtra($wo, $materialIdx);
    }

    private function history(string $col, int $refIdx): Collection {
        return StockTxnLine::with(['txn', 'item:item_idx,item_code,item_name,spec,spec_unit', 'lot:lot_idx,lot_no,lot_title', 'warehouse:warehouse_idx,warehouse_name'])
            ->whereHas('txn', fn ($q) => $q->where('txn_type', MaterialIssueService::TXN_TYPE)->where($col, $refIdx))
            ->orderByDesc('stock_txn_line_idx')->get()
            ->map(fn (StockTxnLine $l) => [
                'stock_txn_line_idx' => (int) $l->stock_txn_line_idx, 'work_order_material_idx' => (int) $l->work_order_material_idx,
                'txn_no' => $l->txn?->txn_no, 'occurred_at' => substr((string) $l->txn?->occurred_at, 0, 16),
                'item_code' => $l->item?->item_code, 'item_name' => $l->item?->item_name, 'item_spec' => $l->item?->spec, 'spec_unit' => $l->item?->spec_unit,
                'lot_no' => $l->lot?->lot_no, 'warehouse_name' => $l->warehouse?->warehouse_name,
                'qty' => (float) $l->qty, 'consumed_qty' => (float) $l->consumed_qty, 'status' => $l->line_status, 'remark' => $l->txn?->remark,
            ]);
    }

    // 자재 더하기 : 지시에 이미 있으면 그 줄 · 없으면 외주 공정의 BOM 외 줄 ( 정책 상관없이 )
    public function addMaterial(string $kind, int $refIdx, int $itemIdx): int {
        [$wo, $op, , $head] = $this->resolve($kind, $refIdx);
        $has = WorkOrderMaterial::where('work_order_idx', $wo->work_order_idx)->where('child_item_idx', $itemIdx)->value('work_order_material_idx');
        if ($has) return (int) $has;
        return (int) $this->issues->addExtra($wo, $itemIdx, "외주 {$head['ref_no']}", (int) $op->work_order_op_idx, true)->work_order_material_idx;
    }

    // 출고 — 줄에 자재 줄이 없고 item_idx 만 있으면( 화면에서 고른 「출고 전」 자재 ) 이때 지시의 자재 줄을 만든다( 실패하면 같이 되돌림 )
    public function issue(string $kind, int $refIdx, array $data): array {
        return DB::transaction(function () use ($kind, $refIdx, $data) {
            [$wo, , $col] = $this->resolve($kind, $refIdx);
            $made = [];
            foreach ($data['lines'] as $i => $l) {
                if (!empty($l['work_order_material_idx'])) continue;
                if (empty($l['item_idx'])) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => ['자재를 찾을 수 없습니다. 새로고침하세요.']]);
                }
                $data['lines'][$i]['work_order_material_idx'] = $made[(int) $l['item_idx']] ??= $this->addMaterial($kind, $refIdx, (int) $l['item_idx']);
            }
            $res = $this->issues->issue($wo, $data + [$col => $refIdx]);
            $this->syncRuns($col, $refIdx);   // 이미 입고된 LOT 에도
            return $res;
        });
    }

    // 재고 한 줄( LOT · 창고 )에서 바로 ( 재고관리 LOT 창 [출고 → 외주] · 11-23 )
    public function issueBalance(string $kind, int $refIdx, int $balanceIdx, array $data): array {
        $itemIdx = DB::table('stock_balance')->where('stock_balance_idx', $balanceIdx)->value('item_idx');
        if (!$itemIdx) {
            throw ValidationException::withMessages(['qty' => ['재고를 찾을 수 없습니다. 새로고침하세요.']]);
        }
        $matIdx = $this->addMaterial($kind, $refIdx, (int) $itemIdx);
        return $this->issue($kind, $refIdx, $data + ['lines' => [['work_order_material_idx' => $matIdx, 'stock_balance_idx' => $balanceIdx, 'qty' => $data['qty']]]]);
    }

    // 취소 : 이 건으로 나간 줄만
    public function cancel(string $kind, int $refIdx, array $lineIdxs): int {
        [$wo, , $col] = $this->resolve($kind, $refIdx);
        $mine = StockTxnLine::whereIn('stock_txn_line_idx', $lineIdxs)
            ->whereHas('txn', fn ($q) => $q->where('txn_type', MaterialIssueService::TXN_TYPE)->where($col, $refIdx))->pluck('stock_txn_line_idx')->all();
        if (!$mine) {
            throw ValidationException::withMessages(['lines' => ['이 외주 건의 출고 줄이 아닙니다. 새로고침하세요.']]);
        }
        $gone = StockTxnLine::whereIn('stock_txn_line_idx', $mine)->where('lot_idx', '>', 0)->pluck('lot_idx')->unique()->all();
        $n = $this->issues->cancel($wo, $mine);
        $this->syncRuns($col, $refIdx, $gone);
        return $n;
    }

    // ---- LOT 연결 ( 11-24 ) ----

    // 외주 건으로 나간 자재 LOT ( 살아 있는 출고 줄 )
    private function caseLots(string $col, int $refIdx): array {
        return DB::table('stock_txn_line as l')->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->where('t.txn_type', MaterialIssueService::TXN_TYPE)->where("t.{$col}", $refIdx)
            ->where('l.line_status', 'DONE')->where('l.lot_idx', '>', 0)
            ->distinct()->pluck('l.lot_idx')->map(fn ($x) => (int) $x)->all();
    }

    // 외주 건의 입고로 생긴 실적 ( 살아 있는 · 실적 LOT 있는 )
    private function caseRuns(string $col, int $refIdx): Collection {
        $runIdxs = DB::table('subcon_receipt as rc')->join('subcon_issue as s', 's.subcon_issue_idx', '=', 'rc.subcon_issue_idx')
            ->where('rc.status', 'DONE')->whereNotNull('rc.run_idx')
            ->where($col === 'subcon_order_line_idx' ? 's.subcon_order_line_idx' : 's.subcon_issue_idx', $refIdx)
            ->pluck('rc.run_idx');
        return WorkOrderOpRun::whereIn('run_idx', $runIdxs)->where('is_canceled', 'N')->whereNotNull('lot_idx')->get();
    }

    // 실적에 자재 LOT 더하기 ( 이미 연결된 LOT 는 건너뜀 )
    private function addLots(WorkOrderOpRun $run, array $lots): void {
        if (!$lots || !$run->lot_idx) return;
        $have = WorkOrderRunInput::where('run_idx', $run->run_idx)->where('input_type', WorkOrderRunInput::TYPE_MATERIAL)
            ->where('is_canceled', 'N')->pluck('lot_idx')->map(fn ($x) => (int) $x)->all();
        foreach (array_diff($lots, $have) as $lot) {
            WorkOrderRunInput::create([
                'run_idx' => $run->run_idx, 'input_type' => WorkOrderRunInput::TYPE_MATERIAL, 'lot_idx' => $lot, 'qty' => '0',
                'create_account_idx' => \Illuminate\Support\Facades\Auth::id() ?? 0, 'create_datetime' => now(),
            ]);
        }
    }

    // 외주 입고로 실적이 생길 때 ( SubconService::complete ) — 그 출고의 외주 건( 발주 없는 출고 · 발주 줄 ) 자재 LOT
    public function linkRun(WorkOrderOpRun $run, object $issue): void {
        $lots = $this->caseLots('subcon_issue_idx', (int) $issue->subcon_issue_idx);
        if ($issue->subcon_order_line_idx) {
            $lots = array_merge($lots, $this->caseLots('subcon_order_line_idx', (int) $issue->subcon_order_line_idx));
        }
        $this->addLots($run, array_values(array_unique($lots)));
    }

    // 자재를 내거나 취소한 뒤 : 이 외주 건의 입고 LOT 들에 더하고 · 이 건에 더 없는 LOT( gone )는 해제
    private function syncRuns(string $col, int $refIdx, array $gone = []): void {
        $runs = $this->caseRuns($col, $refIdx);
        if ($runs->isEmpty()) return;
        $live = $this->caseLots($col, $refIdx);
        foreach ($runs as $run) $this->addLots($run, $live);
        $drop = array_values(array_diff($gone, $live));
        if ($drop) {
            WorkOrderRunInput::whereIn('run_idx', $runs->pluck('run_idx'))->where('input_type', WorkOrderRunInput::TYPE_MATERIAL)
                ->whereIn('lot_idx', $drop)->where('is_canceled', 'N')
                ->update(['is_canceled' => 'Y', 'canceled_by' => \Illuminate\Support\Facades\Auth::id(), 'canceled_at' => now()]);
        }
    }
}

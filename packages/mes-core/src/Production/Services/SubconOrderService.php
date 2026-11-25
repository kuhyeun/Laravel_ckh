<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Partner;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;

/**
 * 외주 발주 ( 11-23 · docs/외주발주-설계.md ) — 외주 출고 앞의 선택 단계 ( 없어도 지금 흐름 그대로 · 확정 단계 없음 )
 *
 *   발주서 = 머리( 외주처 · 발주일 · 납기 · 담당 ) + 줄( 작업지시의 외주 공정 하나 · 수량 자유 · 단가 · 금액 · 줄 납기 )
 *     발주 수량이 앞 공정 생산( 출고 기준 )보다 많으면 경고만
 *   고치기 : 출고가 있는 줄은 지우지 못하고 공정도 못 바꾼다 · 취소 = 출고가 하나도 없을 때
 *   상태 : 저장하는 것은 취소( CANCELED )뿐 — 그 밖은 출고 · 입고에서 계산 ( 발주 / 진행 / 완료 )
 *   출고 : 발주서 줄의 [출고] → SubconService::issue( subcon_order_line_idx ) — 외주처 · 공정이 같아야 · 금액 = 출고 수량 × 단가
 */
class SubconOrderService {
    use \MesCore\Support\FormatsQty;

    public const STATUSES = ['ORDERED' => '발주', 'RUN' => '진행', 'DONE' => '완료', 'CANCELED' => '취소'];
    private const EPS = 0.000001;

    public function __construct(private SubconService $subcon) {}

    /** 줄별 출고 · 입고 합 ( 취소 제외 ) — @return array<int, array{issued: float, received: float}> */
    public function lineProgress(array $lineIdxs): array {
        if (!$lineIdxs) return [];
        $issued = DB::table('subcon_issue')->whereIn('subcon_order_line_idx', $lineIdxs)->where('is_canceled', 'N')
            ->groupBy('subcon_order_line_idx')->selectRaw('subcon_order_line_idx, SUM(qty) as n')->pluck('n', 'subcon_order_line_idx');
        $received = DB::table('subcon_receipt as r')->join('subcon_issue as i', 'i.subcon_issue_idx', '=', 'r.subcon_issue_idx')
            ->whereIn('i.subcon_order_line_idx', $lineIdxs)->where('i.is_canceled', 'N')->where('r.status', '!=', 'CANCELED')
            ->groupBy('i.subcon_order_line_idx')->selectRaw('i.subcon_order_line_idx, SUM(r.qty) as n')->pluck('n', 'subcon_order_line_idx');
        $out = [];
        foreach ($lineIdxs as $id) {
            $out[$id] = ['issued' => (float) ($issued[$id] ?? 0), 'received' => (float) ($received[$id] ?? 0)];
        }
        return $out;
    }

    // 상태 ( 취소는 저장 값 · 그 밖은 계산 )
    public function statusOf(string $stored, float $qty, float $issued, float $received): string {
        if ($stored === 'CANCELED') return 'CANCELED';
        if ($qty > self::EPS && $received + self::EPS >= $qty) return 'DONE';
        return ($issued > self::EPS || $received > self::EPS) ? 'RUN' : 'ORDERED';
    }

    /**
     * 저장 ( 새로 / 고치기 )
     * @param array{subcon_order_idx:?int, partner_idx:int, order_date:string, due_date:?string, manager_idx:?int, remark:?string,
     *              lines: array<array{subcon_order_line_idx:?int, work_order_op_idx:int, qty:string, unit_price:?string, due_date:?string, remark:?string}>} $d
     * @return array{0: object, 1: string[]} 발주 · 경고
     */
    public function save(array $d): array {
        return DB::transaction(function () use ($d) {
            if (!Partner::whereKey($d['partner_idx'])->where('is_subcon', 'Y')->where('is_use', 'Y')->exists()) {
                throw ValidationException::withMessages(['partner_idx' => ['사용 중인 외주처를 고르세요.']]);
            }
            $idx   = $d['subcon_order_idx'] ?? null;
            $order = $idx ? DB::table('subcon_order')->where('subcon_order_idx', $idx)->lockForUpdate()->first() : null;
            if ($idx && !$order) {
                throw ValidationException::withMessages(['subcon_order_idx' => ['없는 발주입니다. 새로고침하세요.']]);
            }
            if ($order && $order->status === 'CANCELED') {
                throw ValidationException::withMessages(['subcon_order_idx' => ['취소된 발주는 고칠 수 없습니다.']]);
            }
            $lines = array_values($d['lines'] ?? []);
            if (!$lines) {
                throw ValidationException::withMessages(['lines' => ['외주 공정을 한 줄 이상 넣으세요.']]);
            }

            $existing = $order ? DB::table('subcon_order_line')->where('subcon_order_idx', $order->subcon_order_idx)->get()->keyBy('subcon_order_line_idx') : collect();
            $progress = $this->lineProgress($existing->keys()->all());
            // 출고가 있는 줄 : 머리 외주처를 바꾸거나 · 줄을 지우거나 · 공정을 바꾸면 막는다
            $issuedLines = $existing->filter(fn ($l) => ($progress[$l->subcon_order_line_idx]['issued'] ?? 0) > self::EPS);
            if ($order && (int) $order->partner_idx !== (int) $d['partner_idx'] && $issuedLines->isNotEmpty()) {
                throw ValidationException::withMessages(['partner_idx' => ['출고가 있는 발주는 외주처를 바꿀 수 없습니다.']]);
            }
            $keep = collect($lines)->pluck('subcon_order_line_idx')->filter()->map(fn ($x) => (int) $x)->all();
            foreach ($issuedLines as $l) {
                if (!in_array((int) $l->subcon_order_line_idx, $keep, true)) {
                    throw ValidationException::withMessages(['lines' => ["{$l->line_no}번 줄은 출고가 있어 지울 수 없습니다. 출고를 먼저 취소하세요."]]);
                }
            }

            // 공정 · 작업지시 확인 + 경고 ( 앞 공정 생산보다 많이 발주 )
            $warnings = [];
            $opRows   = [];
            foreach ($lines as $i => $l) {
                $op = WorkOrderOp::with('process')->find((int) $l['work_order_op_idx']);
                if (!$op || !$op->isSubconOp()) {
                    throw ValidationException::withMessages(["lines.{$i}.work_order_op_idx" => ['외주 공정이 아닙니다. 새로고침하세요.']]);
                }
                $was = !empty($l['subcon_order_line_idx']) ? $existing->get((int) $l['subcon_order_line_idx']) : null;
                if (!empty($l['subcon_order_line_idx']) && !$was) {
                    throw ValidationException::withMessages(["lines.{$i}.work_order_op_idx" => ['이 발주의 줄이 아닙니다. 새로고침하세요.']]);
                }
                if ($was && (int) $was->work_order_op_idx !== (int) $op->work_order_op_idx && ($progress[$was->subcon_order_line_idx]['issued'] ?? 0) > self::EPS) {
                    throw ValidationException::withMessages(["lines.{$i}.work_order_op_idx" => ['출고가 있는 줄은 공정을 바꿀 수 없습니다.']]);
                }
                $wo = WorkOrder::find($op->work_order_idx);
                if (!$was && (!in_array($wo?->status, ['WAIT', 'RUN'], true) || $op->status === 'DONE')) {
                    throw ValidationException::withMessages(["lines.{$i}.work_order_op_idx" => ["{$wo?->wo_no} : 대기 · 진행 중인 작업지시의 끝나지 않은 외주 공정만 발주할 수 있습니다."]]);
                }
                $qty = (float) $l['qty'];
                if ($qty <= self::EPS) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => ['발주 수량은 0보다 커야 합니다.']]);
                }
                $ops = WorkOrderOp::with('process')->where('work_order_idx', $op->work_order_idx)->orderBy('seq')->get();
                $av  = $this->subcon->available($wo, $ops, $ops->firstWhere('work_order_op_idx', $op->work_order_op_idx));
                $hasPrev = $ops->contains(fn ($x) => $x->seq < $op->seq && ($x->isQtyOp() || $x->isInspectionOp()));
                if ($hasPrev && $qty > $av['base'] + self::EPS) {
                    $warnings[] = "{$wo->wo_no} {$op->process?->process_name} : 앞 공정 생산 {$this->num($av['base'])} — 발주 {$this->num($qty)} ( 출고는 앞 공정에서 만든 만큼만 됩니다 )";
                }
                $issued = $was ? ($progress[$was->subcon_order_line_idx]['issued'] ?? 0) : 0;
                if ($issued > $qty + self::EPS) {
                    $warnings[] = "{$wo->wo_no} {$op->process?->process_name} : 이미 출고 {$this->num($issued)} — 발주 {$this->num($qty)} 보다 많습니다.";
                }
                $opRows[$i] = [$op, $was];
            }

            $at   = Carbon::parse($d['order_date']);
            $head = [
                'partner_idx' => (int) $d['partner_idx'],
                'order_date'  => $d['order_date'],
                'due_date'    => $d['due_date'] ?? null,
                'manager_idx' => $d['manager_idx'] ?? null,
                'remark'      => $d['remark'] ?? null,
            ];
            if ($order) {
                DB::table('subcon_order')->where('subcon_order_idx', $order->subcon_order_idx)->update($head + ['update_account_idx' => Auth::id()]);
                $orderIdx = (int) $order->subcon_order_idx;
            } else {
                $orderIdx = DB::table('subcon_order')->insertGetId($head + [
                    'order_no' => \MesCore\Support\DocNumber::next('SCO-' . $at->format('ymd') . '-', 'subcon_order', 'order_no'),
                    'status' => 'ORDERED', 'create_account_idx' => Auth::id() ?? 0, 'create_datetime' => now(),
                ]);
            }

            // 줄 : 남길 줄은 고치고 · 새 줄은 넣고 · 빠진 줄( 출고 없음 )은 지운다 — 줄 번호 = 화면 순서
            $kept = [];
            foreach ($lines as $i => $l) {
                [$op, $was] = $opRows[$i];
                $qty   = (float) $l['qty'];
                $price = round((float) ($l['unit_price'] ?? 0), 2);
                $row   = [
                    'line_no' => $i + 1, 'work_order_idx' => $op->work_order_idx, 'work_order_op_idx' => $op->work_order_op_idx,
                    'qty' => $this->str($qty), 'unit_price' => $price, 'amount' => round($qty * $price, 2),
                    'due_date' => $l['due_date'] ?? null, 'remark' => $l['remark'] ?? null,
                ];
                if ($was) {
                    DB::table('subcon_order_line')->where('subcon_order_line_idx', $was->subcon_order_line_idx)->update($row);
                    $kept[] = (int) $was->subcon_order_line_idx;
                } else {
                    $kept[] = DB::table('subcon_order_line')->insertGetId($row + ['subcon_order_idx' => $orderIdx]);
                }
            }
            DB::table('subcon_order_line')->where('subcon_order_idx', $orderIdx)->whereNotIn('subcon_order_line_idx', $kept ?: [0])->delete();

            return [DB::table('subcon_order')->where('subcon_order_idx', $orderIdx)->first(), $warnings];
        });
    }

    // 취소 — 출고가 하나도 없을 때
    /**
     * 발주 취소 ( 11-24 ) — 이력이 하나도 없으면 지우고( DELETED ), 있으면 취소( CANCELED )
     *   이력 = 그 발주 줄로 나간 외주 출고( 취소된 것 포함 ) · 그 줄로 나간 자재 출고 전표( 출고관리 [외주] · 취소 포함 )
     *   살아 있는 외주 출고가 있으면 막는다 ( 출고를 먼저 취소 )
     */
    public function cancel(int $orderIdx): string {
        return DB::transaction(function () use ($orderIdx) {
            $order = DB::table('subcon_order')->where('subcon_order_idx', $orderIdx)->lockForUpdate()->first();
            if (!$order || $order->status === 'CANCELED') {
                throw ValidationException::withMessages(['subcon_order_idx' => ['이미 취소되었거나 없는 발주입니다.']]);
            }
            $lineIdxs = DB::table('subcon_order_line')->where('subcon_order_idx', $orderIdx)->pluck('subcon_order_line_idx')->all();
            if ($lineIdxs && DB::table('subcon_issue')->whereIn('subcon_order_line_idx', $lineIdxs)->where('is_canceled', 'N')->exists()) {
                throw ValidationException::withMessages(['subcon_order_idx' => ["{$order->order_no} 은 출고가 있어 취소할 수 없습니다. 출고를 먼저 취소하세요."]]);
            }
            if (!$this->hasHistory($lineIdxs)) {
                DB::table('subcon_order_line')->where('subcon_order_idx', $orderIdx)->delete();
                DB::table('subcon_order')->where('subcon_order_idx', $orderIdx)->delete();
                return 'DELETED';
            }
            DB::table('subcon_order')->where('subcon_order_idx', $orderIdx)->update(['status' => 'CANCELED', 'update_account_idx' => Auth::id()]);
            return 'CANCELED';
        });
    }

    // 발주 줄에 남은 이력 ( 외주 출고 · 자재 출고 — 취소된 것 포함 ) — 없으면 지울 수 있다
    public function hasHistory(array $lineIdxs): bool {
        if (!$lineIdxs) return false;
        return DB::table('subcon_issue')->whereIn('subcon_order_line_idx', $lineIdxs)->exists()
            || DB::table('stock_txn')->whereIn('subcon_order_line_idx', $lineIdxs)->exists();
    }
    // 발주마다 이력 있음 ( 목록 · 발주서 — 취소 확인창이 「삭제 / 취소」를 알린다 )
    public function historyByOrder(array $orderIdxs): array {
        if (!$orderIdxs) return [];
        $lines = DB::table('subcon_order_line')->whereIn('subcon_order_idx', $orderIdxs)->pluck('subcon_order_idx', 'subcon_order_line_idx');
        if ($lines->isEmpty()) return [];
        $used = DB::table('subcon_issue')->whereIn('subcon_order_line_idx', $lines->keys())->pluck('subcon_order_line_idx')
            ->merge(DB::table('stock_txn')->whereIn('subcon_order_line_idx', $lines->keys())->pluck('subcon_order_line_idx'))->unique();
        return $used->map(fn ($l) => (int) $lines[$l])->unique()->mapWithKeys(fn ($o) => [$o => true])->all();
    }

    /** 발주서 한 건 ( 화면 · 인쇄 ) */
    public function show(int $orderIdx): ?array {
        $o = DB::table('subcon_order as o')->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
            ->leftJoin('account_member as m', 'm.account_idx', '=', 'o.manager_idx')
            ->where('o.subcon_order_idx', $orderIdx)->first(['o.*', 'p.partner_name', 'm.user_name as manager_name']);
        if (!$o) return null;
        $lines = DB::table('subcon_order_line')->where('subcon_order_idx', $orderIdx)->orderBy('line_no')->get();
        $prog  = $this->lineProgress($lines->pluck('subcon_order_line_idx')->all());
        $rows  = $this->lineRows($lines, $prog);
        $qty   = $rows->sum('qty');
        return [
            'subcon_order_idx' => (int) $o->subcon_order_idx, 'order_no' => $o->order_no,
            'partner_idx' => (int) $o->partner_idx, 'partner_name' => $o->partner_name,
            'order_date' => $o->order_date, 'due_date' => $o->due_date, 'manager_idx' => $o->manager_idx ? (int) $o->manager_idx : null, 'manager_name' => $o->manager_name,
            'remark' => $o->remark, 'stored_status' => $o->status,
            'has_history' => $this->hasHistory($lines->pluck('subcon_order_line_idx')->all()),   // 없으면 [발주 취소] = 삭제
            'status' => $this->statusOf($o->status, $qty, $rows->sum('issued_qty'), $rows->sum('received_qty')),
            'total' => round($rows->sum('amount'), 2),
            'lines' => $rows->values()->all(),
        ];
    }

    // 줄 → 화면 행 ( 작업지시 · 품목 · 공정 · 앞 공정 생산 · 출고 가능 · 출고 · 입고 )
    public function lineRows(Collection $lines, array $prog): Collection {
        if ($lines->isEmpty()) return collect();
        $woIds   = $lines->pluck('work_order_idx')->unique()->all();
        $wos     = WorkOrder::with('item')->whereIn('work_order_idx', $woIds)->get()->keyBy('work_order_idx');
        $opsByWo = WorkOrderOp::with('process')->whereIn('work_order_idx', $woIds)->orderBy('seq')->get()->groupBy('work_order_idx');
        return $lines->map(function ($l) use ($wos, $opsByWo, $prog) {
            $wo  = $wos[$l->work_order_idx] ?? null;
            $ops = $opsByWo[$l->work_order_idx] ?? collect();
            $op  = $ops->firstWhere('work_order_op_idx', $l->work_order_op_idx);
            $av  = $wo && $op ? $this->subcon->available($wo, $ops, $op) : ['base' => 0, 'issued' => 0, 'available' => 0];
            $prev = $op ? $ops->filter(fn ($x) => $x->seq < $op->seq && ($x->isQtyOp() || $x->isInspectionOp()))->last() : null;
            return [
                'subcon_order_line_idx' => (int) $l->subcon_order_line_idx, 'line_no' => (int) $l->line_no,
                'work_order_idx' => (int) $l->work_order_idx, 'work_order_op_idx' => (int) $l->work_order_op_idx,
                'wo_no' => $wo?->wo_no, 'wo_status' => $wo?->status, 'lot_mode' => $wo?->lot_mode,
                'item_code' => $wo?->item?->item_code, 'item_name' => $wo?->item?->item_name, 'item_spec' => $wo?->item?->spec, 'spec_unit' => $wo?->item?->spec_unit,
                'process_name' => $op?->process?->process_name, 'op_status' => $op?->status,
                'prev_label' => $prev ? $prev->process?->process_name : '지시 수량', 'has_prev' => (bool) $prev,
                'base_qty' => $av['base'], 'op_issued_qty' => $av['issued'], 'available_qty' => $av['available'],
                'qty' => (float) $l->qty, 'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount,
                'due_date' => $l->due_date, 'remark' => $l->remark,
                'issued_qty' => $prog[$l->subcon_order_line_idx]['issued'] ?? 0.0,
                'received_qty' => $prog[$l->subcon_order_line_idx]['received'] ?? 0.0,
                'is_last' => $op ? app(OpResultService::class)->receivesOnRun($ops, $op) : false,
            ];
        });
    }
}

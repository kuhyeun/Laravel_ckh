<?php

namespace MesCore\Shipment\Controllers;

use Carbon\Carbon;
use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// 클레임관리 ( 7004 · 11-05 ) — 출하 완료 건의 품목 줄에 클레임 수량 · 내용을 남긴다 ( 기록만 — 재고 · 출하 수량은 그대로 )
//   클레임 1건 = 출하 1건 · 줄 = 그 출하의 품목 여러 개 · 줄마다 첨부( claim_line )
//   수량 : 그 출하 줄의 클레임 합( 다른 클레임 포함 )이 출하 수량을 넘을 수 없다
//   줄 빼기 · 삭제 : 첨부가 남은 줄은 막는다 ( 첨부를 먼저 지운다 )
class ClaimController extends Controller {
    use SearchesKeyword, RespondsWithList;

    private const EPS = 0.000001;
    const SORTABLE = ['claim_no', 'claim_date', 'shipment_no', 'shipped_at', 'partner_name', 'item_code', 'shipped_qty', 'qty', 'claim_type', 'file_count', 'creator_name'];

    protected function listTieBreaker(): ?string {
        return 'cl.claim_line_idx';
    }

    // 목록 : 클레임 줄 1개 = 1행
    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30', 'partner_idx' => 'nullable|integer',
        ]);
        $q = DB::table('claim_line as cl')
            ->join('claim as c', 'c.claim_idx', '=', 'cl.claim_idx')
            ->join('shipment as s', 's.shipment_idx', '=', 'c.shipment_idx')
            ->join('shipment_line as sl', 'sl.shipment_line_idx', '=', 'cl.shipment_line_idx')
            ->join('partner as pt', 'pt.partner_idx', '=', 'c.partner_idx')
            ->join('item as i', 'i.item_idx', '=', 'cl.item_idx')
            ->leftJoin('system_code as sc', fn ($j) => $j->on('sc.system_code_key', '=', 'cl.claim_type_code')->where('sc.system_code_group_key', 'DEFECT_TYPE'))
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'c.create_account_idx')
            ->leftJoinSub(DB::table('attachment')->where('ref_type', 'claim_line')->groupBy('ref_idx')->selectRaw('ref_idx, COUNT(*) as n'), 'att', 'att.ref_idx', '=', 'cl.claim_line_idx')
            ->select('cl.claim_line_idx', 'cl.qty', 'cl.content', 'c.claim_idx', 'c.claim_no', 'c.claim_date', 'c.remark', 's.shipment_no', 's.shipped_at', 'sl.qty as shipped_qty',
                'pt.partner_name', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'sc.system_code_name as claim_type',
                DB::raw("COALESCE(NULLIF(u.user_name, ''), u.user_id) as creator_name"), DB::raw('COALESCE(att.n, 0) as file_count'));
        if (!empty($v['from'])) $q->where('c.claim_date', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('c.claim_date', '<=', $v['to']);
        if (!empty($v['partner_idx'])) $q->where('c.partner_idx', $v['partner_idx']);
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'claim_no'    => fn ($q, $kw) => $q->where('c.claim_no', 'like', $kw),
            'shipment_no' => fn ($q, $kw) => $q->where('s.shipment_no', 'like', $kw),
            'item_code'   => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name'   => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
        ]);
        // 서버 페이징 · 정렬 · 엑셀 전체 ( 전에는 기간을 넓히면 전부 내려받았다 )
        $totalQty = (float) (clone $q)->sum('cl.qty');   // 조건 전체의 클레임 수량 합 ( 화면 머리 — 페이지와 상관없이 )
        $q->orderByDesc('c.claim_date')->orderByDesc('c.claim_idx')->orderBy('sl.line_no');
        $res = $this->listResponse($request, $q, self::SORTABLE, 'rows', fn ($r) => [
            'claim_line_idx' => $r->claim_line_idx,
            'claim_idx'      => $r->claim_idx,
            'claim_no'       => $r->claim_no,
            'claim_date'     => substr((string) $r->claim_date, 0, 10),
            'shipment_no'    => $r->shipment_no,
            'shipped_at'     => substr((string) $r->shipped_at, 0, 10),
            'partner_name'   => $r->partner_name,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'item_spec'      => $r->item_spec,
            'spec_unit'      => $r->spec_unit,
            'shipped_qty'    => (float) $r->shipped_qty,
            'qty'            => (float) $r->qty,
            'claim_type'     => $r->claim_type,
            'content'        => $r->content,
            'remark'         => $r->remark,
            'file_count'     => (int) $r->file_count,
            'creator_name'   => $r->creator_name,
        ]);
        return $res->setData($res->getData(true) + ['total_qty' => $totalQty]);
    }

    public function formData() {
        return response()->json([
            'partners' => Partner::whereIn('partner_idx', DB::table('claim')->select('partner_idx')->distinct())->orderBy('partner_name')->forPicker()->get(),
            'today'    => now()->toDateString(),
        ]);
    }

    // 클레임을 걸 출하 ( 완료 ) — 최근 순 · 검색어( 출하번호 · 고객사 · 품번 · 품명 )
    public function shipments(Request $request) {
        $v = $request->validate(['keyword' => 'nullable|string|max:100']);
        $q = DB::table('shipment as s')->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')->where('s.status', 'DONE')
            ->select('s.shipment_idx', 's.shipment_no', 's.shipped_at', 'pt.partner_name',
                DB::raw('(SELECT COUNT(*) FROM shipment_line x WHERE x.shipment_idx = s.shipment_idx) as line_count'));
        if (filled($v['keyword'] ?? null)) {
            $kw = '%' . addcslashes(trim($v['keyword']), '%_\\') . '%';
            $q->where(fn ($w) => $w->where('s.shipment_no', 'like', $kw)->orWhere('pt.partner_name', 'like', $kw)
                ->orWhereExists(fn ($e) => $e->from('shipment_line as l')->join('item as i', 'i.item_idx', '=', 'l.item_idx')
                    ->whereColumn('l.shipment_idx', 's.shipment_idx')->where(fn ($x) => $x->where('i.item_code', 'like', $kw)->orWhere('i.item_name', 'like', $kw))));
        }
        $list = $q->orderByDesc('s.shipped_at')->orderByDesc('s.shipment_idx')->limit(100)->get();
        // 품목 ( 출하 줄 순서 — 품번 · 품명 · 수량 ) : 고르기 전에 어떤 품목인지 보이게 ( 11-05 )
        $items = DB::table('shipment_line as l')->join('item as i', 'i.item_idx', '=', 'l.item_idx')->whereIn('l.shipment_idx', $list->pluck('shipment_idx'))
            ->orderBy('l.line_no')->get(['l.shipment_idx', 'l.qty', 'i.item_code', 'i.item_name', 'i.spec_unit'])->groupBy('shipment_idx');
        return response()->json(['rows' => $list->map(fn ($r) => [
            'shipment_idx' => $r->shipment_idx, 'shipment_no' => $r->shipment_no, 'shipped_at' => substr((string) $r->shipped_at, 0, 16),
            'partner_name' => $r->partner_name, 'line_count' => (int) $r->line_count,
            'items'        => ($items[$r->shipment_idx] ?? collect())->map(fn ($i) => [
                'item_code' => $i->item_code, 'item_name' => $i->item_name, 'qty' => (float) $i->qty, 'spec_unit' => $i->spec_unit,
            ])->values(),
        ])]);
    }

    // 클레임 한 건 ( 새로 : shipment_idx 만 ) — 출하 품목 줄 전부 + 이 클레임 값 + 다른 클레임 합
    public function show(Request $request) {
        $v = $request->validate(['claim_idx' => 'nullable|integer', 'shipment_idx' => 'nullable|integer']);
        $claim = !empty($v['claim_idx']) ? DB::table('claim')->where('claim_idx', $v['claim_idx'])->first() : null;
        $shipmentIdx = $claim?->shipment_idx ?? ($v['shipment_idx'] ?? null);
        $s = DB::table('shipment as s')->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')->where('s.shipment_idx', $shipmentIdx)
            ->first(['s.shipment_idx', 's.shipment_no', 's.shipped_at', 's.status', 'pt.partner_name']);
        if (!$s || ($claim === null && $s->status !== 'DONE')) {
            return response()->json(['message' => '출하 완료 건만 클레임을 등록할 수 있습니다.'], 422);
        }
        $mine = $claim ? DB::table('claim_line')->where('claim_idx', $claim->claim_idx)->get()->keyBy('shipment_line_idx') : collect();
        $others = $this->claimedByLine($s->shipment_idx, $claim?->claim_idx);
        $lines = DB::table('shipment_line as l')->join('item as i', 'i.item_idx', '=', 'l.item_idx')->where('l.shipment_idx', $s->shipment_idx)->orderBy('l.line_no')
            ->get(['l.shipment_line_idx', 'l.line_no', 'l.qty', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit'])
            ->map(function ($l) use ($mine, $others) {
                $m = $mine[$l->shipment_line_idx] ?? null;
                return [
                    'shipment_line_idx' => $l->shipment_line_idx,
                    'line_no'           => $l->line_no,
                    'item_code'         => $l->item_code,
                    'item_name'         => $l->item_name,
                    'item_spec'         => $l->item_spec,
                    'spec_unit'         => $l->spec_unit,
                    'shipped_qty'       => (float) $l->qty,
                    'other_claimed'     => (float) ($others[$l->shipment_line_idx] ?? 0),
                    'claim_line_idx'    => $m?->claim_line_idx,
                    'qty'               => $m ? (float) $m->qty : null,
                    'claim_type_code'   => $m?->claim_type_code ?? '',
                    'content'           => $m?->content ?? '',
                ];
            })->values();
        return response()->json(['claim' => $claim ? [
            'claim_idx' => $claim->claim_idx, 'claim_no' => $claim->claim_no, 'claim_date' => substr((string) $claim->claim_date, 0, 10), 'remark' => $claim->remark,
        ] : null, 'shipment' => $s, 'lines' => $lines, 'today' => now()->toDateString()]);
    }

    public function save(Request $request) {
        $d = $request->validate([
            'claim_idx'                 => 'nullable|integer',
            'shipment_idx'              => 'required|integer',
            'claim_date'                => 'required|date_format:Y-m-d',
            'remark'                    => 'nullable|string|max:2000',
            'lines'                     => 'required|array|min:1|max:200',
            'lines.*.shipment_line_idx' => 'required|integer',
            'lines.*.qty'               => 'required|numeric|gt:0|max:999999999999',
            'lines.*.claim_type_code'   => 'nullable|string|max:30',
            'lines.*.content'           => 'nullable|string|max:500',
        ], ['lines.required' => '클레임 수량을 넣은 품목이 하나 이상 있어야 합니다.', 'lines.*.qty.gt' => '수량은 0보다 커야 합니다.'], ['claim_date' => '접수일']);

        $claim = DB::transaction(function () use ($d) {
            $claim = !empty($d['claim_idx']) ? DB::table('claim')->where('claim_idx', $d['claim_idx'])->lockForUpdate()->first() : null;
            if (!empty($d['claim_idx']) && !$claim) {
                throw ValidationException::withMessages(['claim_idx' => ['없는 클레임입니다.']]);
            }
            $shipmentIdx = $claim?->shipment_idx ?? (int) $d['shipment_idx'];
            $s = DB::table('shipment')->where('shipment_idx', $shipmentIdx)->first();
            if (!$s || (!$claim && $s->status !== 'DONE')) {
                throw ValidationException::withMessages(['shipment_idx' => ['출하 완료 건만 클레임을 등록할 수 있습니다.']]);
            }
            $sLines = DB::table('shipment_line')->where('shipment_idx', $shipmentIdx)->lockForUpdate()->get()->keyBy('shipment_line_idx');
            $others = $this->claimedByLine($shipmentIdx, $claim?->claim_idx);
            $errors = [];
            foreach ($d['lines'] as $i => $l) {
                $sl = $sLines[$l['shipment_line_idx']] ?? null;
                if (!$sl) { $errors["lines.{$i}.qty"] = ['이 출하의 품목이 아닙니다.']; continue; }
                $max = (float) $sl->qty - (float) ($others[$sl->shipment_line_idx] ?? 0);
                if ((float) $l['qty'] > $max + self::EPS) {
                    $errors["lines.{$i}.qty"] = ['출하 수량( ' . $this->num((float) $sl->qty) . ' ) 중 다른 클레임을 빼면 ' . $this->num(max(0, $max)) . ' 까지 넣을 수 있습니다.'];
                }
            }
            if ($errors) throw ValidationException::withMessages($errors);

            $by  = Auth::id() ?? 0;
            $now = now();
            if ($claim) {
                DB::table('claim')->where('claim_idx', $claim->claim_idx)->update(['claim_date' => $d['claim_date'], 'remark' => $d['remark'] ?? null, 'update_account_idx' => $by]);
            } else {
                $idx = DB::table('claim')->insertGetId([
                    'claim_no' => $this->nextNo(Carbon::parse($d['claim_date'])), 'shipment_idx' => $shipmentIdx, 'partner_idx' => $s->partner_idx,
                    'claim_date' => $d['claim_date'], 'remark' => $d['remark'] ?? null, 'create_account_idx' => $by, 'create_datetime' => $now,
                ]);
                $claim = DB::table('claim')->where('claim_idx', $idx)->first();
            }

            // 줄 : 있으면 고치고 · 없으면 넣고 · 빠진 줄은 지운다( 첨부가 있으면 막음 )
            $keep = collect($d['lines'])->pluck('shipment_line_idx')->map(fn ($x) => (int) $x)->all();
            $gone = DB::table('claim_line')->where('claim_idx', $claim->claim_idx)->whereNotIn('shipment_line_idx', $keep)->pluck('claim_line_idx');
            $this->blockIfFiles($gone->all(), '첨부가 있는 품목 줄은 뺄 수 없습니다. 그 줄의 첨부를 먼저 지우세요.');
            DB::table('claim_line')->whereIn('claim_line_idx', $gone)->delete();
            foreach ($d['lines'] as $l) {
                $vals = ['qty' => number_format((float) $l['qty'], 6, '.', ''), 'claim_type_code' => ($l['claim_type_code'] ?? null) ?: null, 'content' => $l['content'] ?? null];
                $hit = DB::table('claim_line')->where('claim_idx', $claim->claim_idx)->where('shipment_line_idx', $l['shipment_line_idx'])->first();
                if ($hit) {
                    DB::table('claim_line')->where('claim_line_idx', $hit->claim_line_idx)->update($vals + ['update_account_idx' => $by]);
                } else {
                    DB::table('claim_line')->insert($vals + [
                        'claim_idx' => $claim->claim_idx, 'shipment_line_idx' => $l['shipment_line_idx'], 'item_idx' => $sLines[$l['shipment_line_idx']]->item_idx,
                        'create_account_idx' => $by, 'create_datetime' => $now,
                    ]);
                }
            }
            return $claim;
        });
        return response()->json(['message' => 'Success', 'claim_idx' => $claim->claim_idx, 'claim_no' => $claim->claim_no]);
    }

    public function delete(Request $request) {
        $d = $request->validate(['claim_idx' => 'required|integer']);
        DB::transaction(function () use ($d) {
            $lines = DB::table('claim_line')->where('claim_idx', $d['claim_idx'])->pluck('claim_line_idx')->all();
            $this->blockIfFiles($lines, '첨부가 있는 클레임은 삭제할 수 없습니다. 품목 줄의 첨부를 먼저 지우세요.');
            DB::table('claim_line')->where('claim_idx', $d['claim_idx'])->delete();
            DB::table('claim')->where('claim_idx', $d['claim_idx'])->delete();
        });
        return response()->json(['message' => 'Success']);
    }

    // ---- 내부 ----

    /** @return array<int,float> 출하 줄 → 다른 클레임의 수량 합 */
    private function claimedByLine(int $shipmentIdx, ?int $exceptClaim): array {
        return DB::table('claim_line as cl')->join('claim as c', 'c.claim_idx', '=', 'cl.claim_idx')->where('c.shipment_idx', $shipmentIdx)
            ->when($exceptClaim, fn ($q) => $q->where('c.claim_idx', '!=', $exceptClaim))
            ->groupBy('cl.shipment_line_idx')->selectRaw('cl.shipment_line_idx, SUM(cl.qty) as n')->pluck('n', 'shipment_line_idx')->map(fn ($n) => (float) $n)->all();
    }

    private function blockIfFiles(array $lineIdxs, string $msg): void {
        if ($lineIdxs && DB::table('attachment')->where('ref_type', 'claim_line')->whereIn('ref_idx', $lineIdxs)->exists()) {
            throw ValidationException::withMessages(['lines' => [$msg]]);
        }
    }

    private function nextNo(Carbon $date): string {
        $head = 'CL-' . $date->format('ymd') . '-';
        $last = DB::table('claim')->where('claim_no', 'like', $head . '%')->lockForUpdate()->orderByDesc('claim_no')->value('claim_no');
        return $head . str_pad((string) ($last ? ((int) substr($last, strlen($head))) + 1 : 1), 3, '0', STR_PAD_LEFT);
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

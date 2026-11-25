<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MesCore\Auth\Models\User;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\DocTemplate;
use MesCore\Config\Models\Partner;
use MesCore\Config\Services\SiteConfigService;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Production\Services\SubconOrderService;

// 외주 발주 ( 400505 · 11-23 · docs/외주발주-설계.md ) — 목록 · 발주서 · 저장 · 취소 · 인쇄
//   발주 줄 후보( 외주 공정 )는 외주 출고와 같은 목록( SubconController::candidates — 이 메뉴 권한으로 )
class SubconOrderController extends Controller {
    use SearchesKeyword, RespondsWithList;

    private const SORTABLE = ['order_no', 'order_date', 'due_date', 'partner_name'];

    public function __construct(private SubconOrderService $orders) {}

    protected function listTieBreaker(): ?string {
        return 'o.subcon_order_idx';
    }

    public function formData(MenuPermissionService $perm) {
        return response()->json([
            'partners' => Partner::where('is_subcon', 'Y')->where('is_use', 'Y')->orderBy('partner_name')->forPicker()->get(),
            'users'    => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'me'       => User::pickableMe(),
            'today'    => now()->toDateString(),
            'now'      => now()->format('Y-m-d\TH:i'),
            'can_issue' => $perm->canPageForMe('Production/SubconIssue', 'can_write'),   // 발주서 [출고] ( 외주 출고 권한 )
            'can_material' => $perm->canPageForMe('Production/MaterialIssue', 'can_write'),   // 출고 창의 [자재 출고] ( 11-24 )
            'statuses' => collect(SubconOrderService::STATUSES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]);
    }

    // 목록 : 기간( 발주일 · 납기 ) · 외주처 · 상태 · 검색어( 발주번호 · 작업지시 · 품번 · 품명 )
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis' => 'nullable|in:order_date,due_date', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'partner_idx' => 'nullable|integer', 'status' => 'nullable|in:ORDERED,RUN,DONE,CANCELED,OPEN',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
        ]);
        $q = DB::table('subcon_order as o')->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
            ->select('o.*', 'p.partner_name');
        $basis = ($v['date_basis'] ?? 'order_date') === 'due_date' ? 'o.due_date' : 'o.order_date';
        if (!empty($v['from'])) $q->where($basis, '>=', $v['from']);
        if (!empty($v['to']))   $q->where($basis, '<=', $v['to']);
        if (!empty($v['partner_idx'])) $q->where('o.partner_idx', $v['partner_idx']);
        if (($v['status'] ?? '') === 'CANCELED') $q->where('o.status', 'CANCELED');
        elseif (!empty($v['status'])) $q->where('o.status', '!=', 'CANCELED');
        $line = fn ($q, \Closure $fn) => $q->whereExists(fn ($e) => $e->from('subcon_order_line as l')->join('work_order as w', 'w.work_order_idx', '=', 'l.work_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')->whereColumn('l.subcon_order_idx', 'o.subcon_order_idx')->where($fn));
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'order_no'  => fn ($q, $kw) => $q->where('o.order_no', 'like', $kw),
            'wo_no'     => fn ($q, $kw) => $line($q, fn ($w) => $w->where('w.wo_no', 'like', $kw)),
            'item_code' => fn ($q, $kw) => $line($q, fn ($w) => $w->where('i.item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $line($q, fn ($w) => $w->where('i.item_name', 'like', $kw)),
        ]);
        $q->orderByDesc('o.order_date')->orderByDesc('o.subcon_order_idx');

        // 그 페이지 발주의 줄 합 · 출고 · 입고 → 상태 ( 진행 · 완료는 계산 — 상태 거르기는 화면 행에서 )
        $lines = $prog = collect();
        $hist = [];
        $prepare = function ($rows) use (&$lines, &$prog, &$hist) {
            $hist = $this->orders->historyByOrder($rows->pluck('subcon_order_idx')->all());
            $lines = DB::table('subcon_order_line as l')->join('work_order as w', 'w.work_order_idx', '=', 'l.work_order_idx')
                ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
                ->whereIn('l.subcon_order_idx', $rows->pluck('subcon_order_idx'))->orderBy('l.line_no')
                ->get(['l.*', 'w.wo_no', 'i.item_name'])->groupBy('subcon_order_idx');
            $prog = collect($this->orders->lineProgress($lines->flatten()->pluck('subcon_order_line_idx')->all()));
        };
        // 내용( 첫 줄 "지시번호 품명 외 N" ) 정렬 = 첫 줄 지시번호 ( 11-25 )
        $sortable = [...self::SORTABLE, 'summary' => DB::table('subcon_order_line as sl')->join('work_order as sw', 'sw.work_order_idx', '=', 'sl.work_order_idx')
            ->whereColumn('sl.subcon_order_idx', 'o.subcon_order_idx')->orderBy('sl.line_no')->limit(1)->select('sw.wo_no')];
        $res = $this->listResponse($request, $q, $sortable, 'rows', function ($o) use (&$lines, &$prog, &$hist) {
            $mine = $lines[$o->subcon_order_idx] ?? collect();
            $qty = (float) $mine->sum('qty');
            $iss = (float) $mine->sum(fn ($l) => $prog[$l->subcon_order_line_idx]['issued'] ?? 0);
            $rcv = (float) $mine->sum(fn ($l) => $prog[$l->subcon_order_line_idx]['received'] ?? 0);
            $first = $mine->first();
            return [
                'subcon_order_idx' => (int) $o->subcon_order_idx, 'order_no' => $o->order_no, 'partner_name' => $o->partner_name,
                'order_date' => $o->order_date, 'due_date' => $o->due_date,
                'summary' => $first ? $first->wo_no . ' ' . $first->item_name . ($mine->count() > 1 ? ' 외 ' . ($mine->count() - 1) : '') : '',
                'line_count' => $mine->count(), 'qty' => $qty, 'issued_qty' => $iss, 'received_qty' => $rcv,
                'amount' => round((float) $mine->sum('amount'), 2),
                'status' => $this->orders->statusOf($o->status, $qty, $iss, $rcv),
                'remark' => $o->remark,
                'has_history' => isset($hist[$o->subcon_order_idx]),   // 없으면 [발주 취소] = 삭제 ( 11-24 )
            ];
        }, $prepare);
        // 상태( 진행 · 완료 · 발주 )는 계산 값이라 그 페이지 안에서 거른다
        $st = $v['status'] ?? '';
        if (in_array($st, ['ORDERED', 'RUN', 'DONE', 'OPEN'], true)) {
            $data = $res->getData(true);
            $data['rows'] = array_values(array_filter($data['rows'], fn ($r) => $st === 'OPEN' ? in_array($r['status'], ['ORDERED', 'RUN'], true) : $r['status'] === $st));
            return response()->json($data);
        }
        return $res;
    }

    public function show(int $orderIdx) {
        $o = $this->orders->show($orderIdx);
        return $o ? response()->json(['order' => $o]) : response()->json(['message' => 'NotFound'], 404);
    }

    public function save(Request $request) {
        $d = $request->validate([
            'subcon_order_idx' => 'nullable|integer',
            'partner_idx'      => 'required|integer',
            'order_date'       => 'required|date_format:Y-m-d',
            'due_date'         => 'nullable|date_format:Y-m-d',
            'manager_idx'      => ['nullable', 'integer', User::pickableRule()],
            'remark'           => 'nullable|string|max:500',
            'lines'                         => 'required|array|min:1|max:200',
            'lines.*.subcon_order_line_idx' => 'nullable|integer',
            'lines.*.work_order_op_idx'     => 'required|integer',
            'lines.*.qty'                   => 'required|numeric|gt:0|max:999999999999',
            'lines.*.unit_price'            => 'nullable|numeric|min:0|max:9999999999999',
            'lines.*.due_date'              => 'nullable|date_format:Y-m-d',
            'lines.*.remark'                => 'nullable|string|max:200',
        ], ['lines.*.qty.gt' => '발주 수량은 0보다 커야 합니다.'], [
            'partner_idx' => '외주처', 'order_date' => '발주일', 'due_date' => '납기', 'manager_idx' => '담당',
            'lines.*.qty' => '발주 수량', 'lines.*.unit_price' => '단가', 'lines.*.due_date' => '줄 납기',
        ]);
        [$order, $warnings] = $this->orders->save($d);
        return response()->json(['message' => 'Success', 'subcon_order_idx' => (int) $order->subcon_order_idx, 'order_no' => $order->order_no, 'warnings' => $warnings]);
    }

    public function cancel(Request $request) {
        $d = $request->validate(['subcon_order_idx' => 'required|integer']);
        $result = $this->orders->cancel((int) $d['subcon_order_idx']);
        return response()->json(['message' => 'Success', 'result' => $result]);   // DELETED · CANCELED
    }

    // 인쇄 데이터 ( 문서 양식 SUBCON_ORDER — DocPreview )
    public function sheets(Request $request, SiteConfigService $site, PolicyService $policy) {
        $v   = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $our = collect(['name', 'biz_no', 'ceo_name', 'address', 'tel', 'fax'])->mapWithKeys(fn ($k) => [$k => $policy->get("company.{$k}")])->all();
        $docs = collect($v['ids'])->unique()->map(function ($id) use ($our) {
            $o = $this->orders->show((int) $id);
            if (!$o) return null;
            $p = Partner::with('contacts')->find($o['partner_idx']);
            $contact = $p?->contacts->first();   // 담당자 ( 첫 사람 )
            return [
                'order_no' => $o['order_no'], 'order_date' => $o['order_date'], 'due_date' => $o['due_date'], 'manager_name' => $o['manager_name'],
                'remark' => $o['remark'], 'total' => $o['total'], 'total_qty' => collect($o['lines'])->sum('qty'),
                'supplier' => $our,
                'receiver' => ['name' => $p?->partner_name, 'biz_no' => $p?->biz_no, 'ceo_name' => $p?->ceo_name, 'address' => $p?->address, 'tel' => $p?->tel ?: $contact?->phone, 'contact' => $contact?->contact_name,
                    'fax' => $p?->fax, 'email' => $p?->email ?: $contact?->email],   // fax · email : 11-25 ( 이메일은 거래처 → 담당자 )
                'lines' => collect($o['lines'])->values()->map(fn ($l, $k) => [
                    'no' => $k + 1, 'wo_no' => $l['wo_no'], 'item_code' => $l['item_code'], 'item_name' => $l['item_name'], 'item_spec' => $l['item_spec'],
                    'process_name' => $l['process_name'], 'unit' => $l['spec_unit'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'amount' => $l['amount'],
                    'due_date' => $l['due_date'], 'remark' => $l['remark'],
                ])->all(),
            ];
        })->filter()->values();
        return response()->json([
            'docs'     => $docs,
            'images'   => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],
            'template' => DocTemplate::forPrint('SUBCON_ORDER'),
        ]);
    }
}

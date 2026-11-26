<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingLine;
use MesCore\Config\Services\RoutingService;
use MesCore\Config\Services\BomService;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderSheet;
use MesCore\Production\Models\WorkOrderSource;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Production\Services\WorkOrderService;
use MesCore\Production\Services\WorkOrderSheetService;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Stock\Services\LotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 작업지시 ( PRD-02 목록 · PRD-03 등록 / 수정 ) — docs/screens/50-생산.md
//   1단계 : 지시 · 공정 / 자재 사본 · 생산 LOT · 수주 분할 / 병합. 실적은 3단계
//   1단계 보완 : 작업지시서( 머리 ) 한 장에 품목 줄 여럿 — 목록 · 조회 · 저장 · 취소는 지시서 단위, 줄 취소도 따로
class WorkOrderController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const ATTRIBUTES = [
        'start_date'                => '착수 예정일',
        'due_date'                  => '생산 기한',
        'manager_idx'               => '담당자',
        'lines'                     => '품목 줄',
        'lines.*.item_idx'          => '품목',
        'lines.*.target_qty'        => '지시 수량',
        'lines.*.scan_qty'          => '회당 상승 수량',
        'lines.*.lot_rule_idx'      => 'LOT 규칙',
        'lines.*.ops'               => '공정',
        'lines.*.ops.*.process_idx' => '공정',
        'lines.*.ops.*.due_date'    => '공정 기한',
        'lines.*.sources.*.qty'     => '담을 수량',
    ];

    private const SORTABLE = ['sheet_no', 'start_date', 'due_date'];

    // 열 정렬 때 같은 값( 같은 품목 · 같은 날짜 )이면 지시서 순 — 페이지마다 순서가 흔들리지 않게
    protected function listTieBreaker(): ?string {
        return 'sheet_idx';
    }

    public function __construct(private WorkOrderService $orders, private WorkOrderSheetService $sheets, private LotService $lots, private RoutingService $routingSvc, private BomService $bomSvc) {}

    // 목록 : 지시서 행 + 품목 줄( 펼침 ). 기간( 착수 예정 / 생산 기한 ) + 검색어( 지시번호 · 품번 · 품명 · LOT · 수주번호 ) + 출처 + 상태
    //   검색어 · 출처는 그 품목 줄이 든 지시서를 찾는다
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis' => 'nullable|in:start_date,due_date',
            'from'       => 'nullable|date_format:Y-m-d',
            'to'         => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'    => 'nullable|string|max:100',
            'source'     => ['nullable', Rule::in(array_keys(WorkOrder::SOURCES))],
            // OPEN = 대기 · 진행 / ACTIVE = 취소 제외( 목록 기본 — 11-10 · 취소된 줄도 목록에서 뺀다 ) / 그 밖 = 그 상태
            'status'     => ['nullable', Rule::in(array_merge(['OPEN', 'ACTIVE'], array_keys(WorkOrder::STATUSES)))],
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $hideCanceled = ($v['status'] ?? null) === 'ACTIVE';

        $query = WorkOrderSheet::query()
            ->with([
                'manager:account_idx,user_id,user_name',
                'lines' => fn ($q) => $q->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot:lot_idx,lot_no,lot_title', 'sources.salesOrderLine.order:sales_order_idx,order_no'])
                    ->withCount(['ops', 'ops as done_ops_count' => fn ($o) => $o->where('status', 'DONE')])
                    ->when($hideCanceled, fn ($l) => $l->where('status', '!=', 'CANCELED')),
            ])
            ->orderByDesc('sheet_idx');

        $basis = ($v['date_basis'] ?? 'start_date') === 'due_date' ? 'due_date' : 'start_date';
        if (!empty($v['from'])) $query->where($basis, '>=', $v['from']);
        if (!empty($v['to']))   $query->where($basis, '<=', $v['to']);
        if (!empty($v['source'])) $query->whereHas('lines', fn ($q) => $q->where('source', $v['source']));
        $status = $v['status'] ?? null;
        if ($status === 'OPEN')       $query->whereIn('status', ['WAIT', 'RUN']);
        elseif ($status === 'ACTIVE') $query->where('status', '!=', 'CANCELED');
        elseif ($status)              $query->where('status', $status);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('sheet_no', 'like', $kw)->orWhereHas('lines', fn ($r) => $r->where('wo_no', 'like', $kw)),
            'item_code' => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'lot_no'    => fn ($q, $kw) => $q->whereHas('lines.lot', fn ($r) => \MesCore\Stock\Models\Lot::whereNoLike($r, $kw, '')),
            'order_no'  => fn ($q, $kw) => $q->whereHas('lines.sources.salesOrderLine.order', fn ($r) => $r->where('order_no', 'like', $kw)),
        ]);

        // 품목 · 규격 · 재질 정렬 = 지시서의 첫 품목 줄( 머리 행에 보이는 줄 · 줄 번호 순 ) 기준 ( 11-25 )
        $firstItem = fn (string $col) => DB::table('work_order as sw')->join('item as si', 'si.item_idx', '=', 'sw.item_idx')
            ->whereColumn('sw.sheet_idx', 'work_order_sheet.sheet_idx')
            ->when($hideCanceled, fn ($q) => $q->where('sw.status', '!=', 'CANCELED'))
            ->orderBy('sw.line_no')->limit(1)->select("si.{$col}");
        $sortable = [...self::SORTABLE, 'item_label' => $firstItem('item_code'), 'item_spec' => $firstItem('spec'), 'item_material' => $firstItem('material')];

        return $this->listResponse($request, $query, $sortable, 'sheets', function (WorkOrderSheet $s) {
            $lines = $s->lines->map(fn (WorkOrder $w) => $this->lineRow($w))->values();
            $head  = [
                'sheet_idx'   => $s->sheet_idx,
                'sheet_no'    => $s->sheet_no,
                'start_date'  => $s->start_date?->toDateString(),
                'due_date'    => $s->due_date?->toDateString(),
                'manager'     => $s->manager?->user_name ?: $s->manager?->user_id,
                'status'      => $s->status,
                'line_count'  => $lines->count(),
            ];
            // 줄이 하나면 그 줄 값을 지시서 행에 바로, 여럿이면 펼침
            if ($lines->count() === 1) {
                return $head + array_diff_key($lines[0], array_flip(['sheet_no', 'status'])) + ['line_status' => $lines[0]['status']];
            }
            $first = $lines->first();
            return $head + [
                'item_code'   => $first ? $first['item_code'] : null,
                'item_label'  => $first ? $first['item_short'] . ' 외 ' . ($lines->count() - 1) . '건' : '',
                '_children'   => $lines->map(fn ($l) => $l + ['sheet_idx' => $s->sheet_idx])->all(),
            ];
        });
    }

    public function show(int $sheetIdx) {
        $sheet = WorkOrderSheet::with(['lines' => fn ($q) => $q->with(WorkOrderService::FORM_RELATIONS)])->find($sheetIdx);
        if (!$sheet) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['sheet' => $this->toSheetForm($sheet)]);
    }

    // 등록 모달 선택지 : 품목 · 라우팅 · 공정( 설비 후보 포함 ) · LOT 규칙 · 작업자
    // 생산계획표 ( 11-19 ) — 단추를 보일지 ( 정책 production.plan_sheet ) · 데이터 연결은 나중 ( 지금은 화면의 예시 데이터 )
    public function planSheet() {
        return response()->json(['enabled' => (bool) app(\MesCore\Basic\Services\PolicyService::class)->get('production.plan_sheet')]);
    }

    // 생산계획표 머리 ( 11-20 ) — 팀 = 사용자 부서( 공통코드 DEPT ) · 담당자 = 그 부서 사용자 · 설비 = 담당자(정)인 설비 ( 부 는 보지 않는다 )
    //   팀 · 담당자 · 설비를 한 번에 보내고 화면이 팀 · 담당자 체크로 거른다 · 기본 팀 = 로그인한 사람의 부서 ( 없으면 화면이 첫 팀 )
    //   날짜별 작업은 아직 연결 전 ( 화면의 예시 )
    public function planSheetData(Request $request) {
        $teams = DB::table('system_code')->where('system_code_group_key', 'DEPT')->where('is_use', 'Y')
            ->orderBy('sort_order')->orderBy('system_code_key')
            ->get(['system_code_key as value', 'system_code_name as label']);

        $members = User::where('is_use', 'Y')->whereIn('dept_code', $teams->pluck('value'))
            ->orderBy('user_name')->get(['account_idx', 'user_name', 'dept_code']);

        $equipments = DB::table('equipment')->where('is_use', 'Y')->whereIn('manager_idx', $members->pluck('account_idx'))
            ->orderBy('equipment_code')->get(['equipment_idx', 'equipment_code', 'equipment_name', 'manager_idx'])
            ->groupBy('manager_idx');

        return response()->json([
            'teams'   => $teams,
            'team'    => $request->user()?->dept_code,
            'members' => $members->map(fn ($m) => [
                'idx'        => $m->account_idx,
                'name'       => $m->user_name,
                'team'       => $m->dept_code,
                'equipments' => ($equipments[$m->account_idx] ?? collect())->map(fn ($e) => [
                    'idx' => $e->equipment_idx, 'code' => $e->equipment_code, 'name' => $e->equipment_name,
                ])->values(),
            ])->values(),
        ]);
    }

    // 생산계획표 작업 ( 11-20 ) — 작업지시 공정 중 설비가 있는 것 · 기간( from 부터 2주 )과 겹치는 것
    //   날짜 범위 = 작업지시 착수일 ~ 공정 기한 ( 없으면 작업지시 납기 · 그것도 없으면 착수일 ) — 그 사이 매일 그 설비 칸에
    //   대상 = 취소를 뺀 대기 · 진행 · 완료 · 도번 = 품번 · 수량 = 지시 수량 + 단위 · 작업자 = 공정 작업자 ( 11-25 : 설비 · 작업자 여러 개 )
    public function planSheetJobs(Request $request) {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d']]);
        $from = $data['from'];
        $to   = date('Y-m-d', strtotime($from . ' +13 days'));
        $end  = 'COALESCE(o.due_date, w.due_date, w.start_date)';

        // 설비 여러 개 ( 11-25 · MES_1125 #5 ) — 공정의 설비마다 그 설비 칸에 한 건 · 작업자는 공정의 작업자 이름을 모아 ( '김 , 이' )
        $hasEquip = fn ($q) => $q->whereNotNull('o.equipment_idx')
            ->orWhereExists(fn ($x) => $x->from('work_order_op_equipment as oe')->whereColumn('oe.work_order_op_idx', 'o.work_order_op_idx'));
        $rows = DB::table('work_order_op as o')
            ->join('work_order as w', 'w.work_order_idx', '=', 'o.work_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
            ->where($hasEquip)->whereNotNull('w.start_date')
            ->where('w.status', '!=', 'CANCELED')
            ->where('w.start_date', '<=', $to)
            ->whereRaw("{$end} >= ?", [$from])
            ->orderBy('w.start_date')->orderBy('w.wo_no')->orderBy('o.seq')
            ->get([
                'o.work_order_op_idx', 'o.equipment_idx', 'o.worker_idx', 'w.start_date as start', DB::raw("{$end} as end"),
                'i.item_code as no', 'w.target_qty as qty', 'i.spec_unit as unit',
            ]);
        $assigns = WorkOrderService::opAssignments($rows);
        $names   = DB::table('account_member')->whereIn('account_idx', collect($assigns)->pluck('crews')->flatten(1)->pluck('worker_idxs')->flatten()->unique()->all() ?: [0])->pluck('user_name', 'account_idx');

        $jobs = [];
        foreach ($rows as $r) {
            // 설비 묶음마다 그 설비 칸에 — 작업자 = 그 설비의 작업자만
            foreach ($assigns[$r->work_order_op_idx]['crews'] as $c) {
                if (!$c['equipment_idx']) continue;
                $worker = collect($c['worker_idxs'])->map(fn ($id) => $names[$id] ?? null)->filter()->implode(', ');
                $jobs[] = [
                    'idx'       => $r->work_order_op_idx,
                    'equipment' => $c['equipment_idx'],
                    'start'     => $r->start,
                    'end'       => max($r->start, $r->end),   // 기한이 착수일보다 앞이면 착수일 하루
                    'no'        => $r->no,
                    'qty'       => (float) $r->qty,
                    'unit'      => $r->unit ?? '',
                    'worker'    => $worker,
                ];
            }
        }

        return response()->json(['jobs' => $jobs]);
    }

    public function formData(MenuPermissionService $permissions) {
        // 품목 선택지는 보내지 않는다 — 화면의 ItemPicker 가 공용 검색( common.items.search )에서 30개씩
        $processes = Process::with(['equipments.equipment:equipment_idx,equipment_code,equipment_name,is_use'])
            ->where('is_use', 'Y')->orderByRaw("is_system = 'Y' DESC")->orderBy('process_code')->get()
            ->map(fn (Process $p) => $this->processRow($p))->values();

        return response()->json([
            'routings'  => Routing::templates()->where('is_use', 'Y')->orderBy('routing_code')->get(['routing_idx', 'routing_code', 'routing_name', 'is_default']),
            'processes' => $processes,
            'lot'       => [
                'enabled' => $this->lots->enabled(),
                // 새 지시는 실적마다 LOT ( 정책 production.op_lot_tracking — 실적LOT-설계 ) · 저장된 줄은 그 줄의 lot_no( '실적별' )
                'run_lot' => $this->lots->enabled() && (bool) app(\MesCore\Basic\Services\PolicyService::class)->get('production.op_lot_tracking'),
                'rules'   => $this->lots->productionRules()->map(fn ($r) => [
                    'lot_rule_idx' => $r->lot_rule_idx, 'lot_rule_name' => $r->lot_rule_name, 'rule_type' => $r->rule_type,
                ])->values(),
            ],
            'workers'   => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'today'     => now()->toDateString(),
            // 자재 탭의 [출고] — 자재출고 화면 권한이 있을 때만 그 화면 주소
            'issue_url' => $permissions->canPageForMe('Production/MaterialIssue', 'can_read')
                ? Menu::where('page_component', 'Production/MaterialIssue')->orderByRaw("read_only = 'N' DESC")->value('url_path') : null,
        ]);
    }

    // 라우팅의 공정 → 지시 공정 행 ( 등록 창이 공정 탭을 채운다 )
    public function routingOps(int $routingIdx) {
        return response()->json(['ops' => $this->opsFromRouting($routingIdx)]);
    }

    // 품목을 고르면 : 품목 라우팅( ② ) 공정 · 사용 중 BOM 자재( 현재고 ) · 회당 상승 기본값
    //   수주 줄을 주면 그 줄의 수주 라우팅( ③ ) 공정 ( docs/라우팅-3단계-설계.md )
    public function itemDefaults(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'qty' => 'nullable|numeric|min:0', 'sales_order_line_idx' => 'nullable|integer']);
        $item = Item::find($v['item_idx']);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $orderRouting = !empty($v['sales_order_line_idx']) ? $this->routingSvc->orderLineRouting((int) $v['sales_order_line_idx']) : null;
        $routingIdx   = $orderRouting?->routing_idx ?? $item->routing_idx;
        return response()->json([
            'routing_idx' => $routingIdx,
            'ops'         => $routingIdx ? $this->opsFromRouting((int) $routingIdx) : [],
            'bom'         => $this->bomPreview($item->item_idx, !empty($v['sales_order_line_idx']) ? (int) $v['sales_order_line_idx'] : null),
            'scan_qty'    => (float) ($item->pack_qty ?: 1),
            // 먼저 만들 하위 반제품 ( 깊은 것부터 · 개당 소요 · 현재고 ) — 화면의 "반제품 함께 지시"
            'semi_parts'  => app(\MesCore\Production\Services\SemiPartService::class)
                ->forProduct($item->item_idx, !empty($v['sales_order_line_idx']) ? (int) $v['sales_order_line_idx'] : null),
        ]);
    }

    // 수주 줄 고르기 ( 병합 ) : 확정 수주 · 남은 지시 수량이 있는 줄. 품목을 주면 그 품목만( 품목 줄의 [+ 수주 줄] ),
    //   없으면 모든 품목( 지시서의 [+ 수주 줄] — 같은 품목 줄에 병합하거나 새 줄로 )
    public function soLines(Request $request) {
        $v = $request->validate(['item_idx' => 'nullable|integer', 'work_order_idx' => 'nullable|integer']);
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,order_date,status,partner_idx', 'order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,spec,material,item_type,is_use'])
            ->when($v['item_idx'] ?? null, fn ($q, $item) => $q->where('item_idx', $item))
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->whereHas('item', fn ($q) => $q->where('item_type', 'PRODUCT'))
            ->orderBy('due_date')->get()
            ->map(fn (SalesOrderLine $l) => $this->soLineRow($l, $v['work_order_idx'] ?? null))
            ->filter(fn ($l) => $l['remaining'] > 0)->values();

        return response()->json(['lines' => $lines]);
    }

    // 지시서 저장 — 지시서 칸 + 품목 줄 전부 ( 한 트랜잭션 )
    public function save(Request $request) {
        $idx   = $request->input('sheet_idx');
        $sheet = $idx ? WorkOrderSheet::find($idx) : null;
        if ($idx && !$sheet) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $data = $request->validate([
            'start_date'                             => 'required|date_format:Y-m-d',
            'due_date'                               => 'required|date_format:Y-m-d',
            'manager_idx'                            => ['nullable', 'integer', User::pickableRule()],
            'remark'                                 => 'nullable|string|max:2000',
            'lines'                                  => 'required|array|min:1|max:50',
            'lines.*.work_order_idx'                 => 'nullable|integer',
            'lines.*.source'                         => ['nullable', Rule::in(['MANUAL', 'STOCK', 'ORDER'])],
            'lines.*.item_idx'                       => 'required|integer',
            'lines.*.target_qty'                     => 'required|numeric|gt:0|max:999999999999',
            'lines.*.scan_qty'                       => 'nullable|numeric|gt:0|max:999999999999',
            'lines.*.lot_rule_idx'                   => 'nullable|integer',
            'lines.*.routing_idx'                    => 'nullable|integer',
            'lines.*.ops'                            => 'required|array|min:1|max:100',
            'lines.*.ops.*.work_order_op_idx'        => 'nullable|integer',
            'lines.*.ops.*.process_idx'              => 'required|integer',
            'lines.*.ops.*.routing_line_idx'         => 'nullable|integer',
            'lines.*.ops.*.equipment_idx'            => 'nullable|integer',
            'lines.*.ops.*.worker_idx'               => ['nullable', 'integer', User::pickableRule()],
            // 「설비 - 작업자」 묶음 ( 11-25 · MES_1125 #5 ) — 설비마다 그 설비의 작업자들 · 첫 묶음의 설비 · 첫 작업자가 대표
            'lines.*.ops.*.crews'                    => 'nullable|array|max:20',
            'lines.*.ops.*.crews.*.equipment_idx'    => 'nullable|integer',
            'lines.*.ops.*.crews.*.worker_idxs'      => 'nullable|array|max:20',
            'lines.*.ops.*.crews.*.worker_idxs.*'    => ['integer', User::pickableRule()],
            'lines.*.ops.*.due_date'                 => 'nullable|date_format:Y-m-d',
            'lines.*.ops.*.insp_first'               => 'nullable|in:Y,N',
            'lines.*.ops.*.insp_middle'              => 'nullable|in:Y,N',
            'lines.*.ops.*.insp_last'                => 'nullable|in:Y,N',
            'lines.*.ops.*.remark'                   => 'nullable|string|max:200',
            'lines.*.sources'                        => 'nullable|array|max:100',
            'lines.*.sources.*.sales_order_line_idx' => 'required|integer',
            'lines.*.sources.*.qty'                  => 'required|numeric|gt:0|max:999999999999',
            'lines.*.production_plan_idx'            => 'nullable|integer',   // 출처 생산계획 ( 계획에서 만든 지시 )
            // 반제품 지시의 부모 ( 완제품 지시 ) : 저장된 줄이면 parent_work_order_idx · 같이 저장하는 새 줄이면 그 줄의 순번 parent_line
            'lines.*.parent_work_order_idx'          => 'nullable|integer',
            'lines.*.parent_line'                    => 'nullable|integer|min:0',
        ], [
            'lines.required'           => '품목 줄을 하나 이상 넣으세요.',
            'lines.min'                => '품목 줄을 하나 이상 넣으세요.',
            'lines.*.ops.required'     => '공정을 하나 이상 넣으세요.',
            'lines.*.ops.min'          => '공정을 하나 이상 넣으세요.',
            'lines.*.target_qty.gt'    => '지시 수량은 0보다 커야 합니다.',
            'lines.*.sources.*.qty.gt' => '담을 수량은 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        try {
            [$saved, $warnings] = $this->sheets->save($data, $sheet);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('작업지시서 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        $saved->load(['lines' => fn ($q) => $q->with(WorkOrderService::FORM_RELATIONS)]);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'sheet' => $this->toSheetForm($saved)]);
    }

    // 날짜만 고치기 ( 완료된 지시서의 [수정] · 11-25 ) — 착수 예정 · 생산 기한 ( 품목 줄에도 복사 ). 수량 · 공정 · 상태는 그대로
    public function editDate(Request $request) {
        $v     = $request->validate(['sheet_idx' => 'required|integer']);
        $sheet = WorkOrderSheet::find($v['sheet_idx']);
        if (!$sheet) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $warnings = $this->sheets->editDates($sheet, $request->only(['start_date', 'due_date']));

        $saved = $sheet->fresh()->load(['lines' => fn ($q) => $q->with(WorkOrderService::FORM_RELATIONS)]);
        return response()->json(['message' => '날짜를 고쳤습니다.', 'warnings' => $warnings, 'sheet' => $this->toSheetForm($saved)]);
    }

    // 지시서 취소 ( 목록에서 여럿 ) — 각 지시서의 취소 안 된 줄이 모두 대기일 때만. 하나라도 막히면 전부 되돌린다
    public function cancel(Request $request) {
        $v = $request->validate(['sheet_idxs' => 'required|array|min:1|max:500', 'sheet_idxs.*' => 'integer']);
        $sheets = WorkOrderSheet::whereIn('sheet_idx', $v['sheet_idxs'])->get();
        if ($sheets->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        DB::transaction(fn () => $sheets->each(fn (WorkOrderSheet $s) => $this->sheets->cancelSheet($s)));
        return response()->json(['message' => 'Success', 'canceled' => $sheets->count()]);
    }

    // 품목 줄 하나 취소 — 대기 줄만
    public function cancelLine(Request $request) {
        // with_semi : 이 줄을 부모로 둔 반제품 지시도 함께 취소 ( 화면이 물어본 뒤 )
        $v  = $request->validate(['work_order_idx' => 'required|integer', 'with_semi' => 'nullable|boolean']);
        $wo = WorkOrder::find($v['work_order_idx']);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $semi = $this->sheets->cancelLine($wo, !empty($v['with_semi']));
        return response()->json(['message' => 'Success', 'wo_no' => $wo->wo_no, 'semi' => $semi]);
    }

    // ---- 내부 ----

    // 목록의 품목 줄 행
    private function lineRow(WorkOrder $w): array {
        $orderNos = $w->sources->map(fn ($s) => $s->salesOrderLine?->order?->order_no)->filter()->unique()->values();
        return [
            'work_order_idx' => $w->work_order_idx,
            'sheet_no'       => $w->wo_no,
            'source'         => $w->source,
            'item_code'      => $w->item?->item_code,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($w->item),
            'item_spec'      => $w->item?->spec,
            'item_material'  => $w->item?->material,
            'item_short'     => \MesCore\Config\Models\Item::labelOf($w->item, true),   // 머리 행 요약용
            'spec_unit'      => $w->item?->spec_unit,
            'target_qty'     => (float) $w->target_qty,
            'lot_no'         => $w->isRunLot() ? '실적별' : $w->lot?->lot_no,   // RUN 방식 = 실적마다 LOT
            'order_summary'  => $orderNos->isEmpty() ? '' : $orderNos->first() . ($orderNos->count() > 1 ? ' 외 ' . ($orderNos->count() - 1) : ''),
            'ops_count'      => $w->ops_count,
            'done_ops_count' => $w->done_ops_count,
            'status'         => $w->status,
        ];
    }

    private function toSheetForm(WorkOrderSheet $s): array {
        return [
            'sheet_idx'   => $s->sheet_idx,
            'sheet_no'    => $s->sheet_no,
            'start_date'  => $s->start_date?->toDateString(),
            'due_date'    => $s->due_date?->toDateString(),
            'manager_idx' => $s->manager_idx,
            'status'      => $s->status,
            'remark'      => $s->remark,
            'lines'       => $s->lines->map(fn (WorkOrder $w) => $this->toForm($w))->values(),
        ];
    }

    private function processRow(Process $p): array {
        return [
            'process_idx'     => $p->process_idx,
            'process_code'    => $p->process_code,
            'process_name'    => $p->process_name,
            'process_type'    => $p->process_type,
            'inspection_kind' => $p->inspection_kind,
            // 초 · 중 · 종 기본값 ( 공정을 줄에 넣을 때 · 11-18 )
            'insp_first'      => $p->insp_first, 'insp_middle' => $p->insp_middle, 'insp_last' => $p->insp_last,
            'is_system'       => $p->is_system,
            'is_rm_out'       => $p->isRmOut(),   // 작업지시 공정 줄 순번 ( 원자재출고 = M · 11-26 )
            'class_code'      => $p->class_code,
            'equipments'      => $p->equipments
                ->filter(fn ($pe) => $pe->equipment && $pe->equipment->is_use === 'Y')
                ->map(fn ($pe) => [
                    'equipment_idx'  => $pe->equipment_idx,
                    'equipment_name' => $pe->equipment->equipment_name,
                    'is_default'     => $pe->is_default,
                ])->values(),
        ];
    }

    private function opsFromRouting(int $routingIdx): array {
        return RoutingLine::with(['process.equipments.equipment'])->where('routing_idx', $routingIdx)->orderBy('seq')->get()
            ->map(function (RoutingLine $l) {
                $default = $l->process?->equipments->firstWhere('is_default', 'Y');
                return [
                    'process_idx'      => $l->process_idx,
                    'routing_line_idx' => $l->routing_line_idx,
                    'source_routing_line_idx' => $l->source_routing_line_idx,   // 수주 공정이면 품목 공정 줄 ( BOM 투입 지점 미리보기 )
                    'equipment_idx'    => $default?->equipment_idx,
                    'crews'            => $default ? [['equipment_idx' => $default->equipment_idx, 'worker_idxs' => []]] : [],   // 11-25 : 기본 설비 묶음 하나로 시작
                    'cycle_time_sec'   => (float) $l->cycle_time_sec,
                    'insp_first'       => $l->insp_first,
                    'insp_middle'      => $l->insp_middle,
                    'insp_last'        => $l->insp_last,
                    'pre_approve_min'  => (int) $l->pre_approve_min,
                ];
            })->values()->all();
    }

    // 사용 중 BOM 자재 + 현재고( 잔고 합 — 참고 )
    // 자재 미리보기 + 현재고( 잔고 합 — 참고 ) — 수주 줄을 주면 그 줄의 수주 자재, 아니면 품목 활성 BOM · 대체 자재 포함
    private function bomPreview(int $itemIdx, ?int $salesOrderLineIdx = null): ?array {
        $bom = ($salesOrderLineIdx ? $this->bomSvc->orderLineBom($salesOrderLineIdx) : null)
            ?? Bom::itemBoms()->where('item_idx', $itemIdx)->where('is_active', 'Y')->first();
        if (!$bom) {
            return null;
        }
        $bom->load(['lines.childItem:item_idx,item_code,item_name,spec,material,spec_unit', 'lines.substitutes.childItem:item_idx,item_code,item_name,spec,material,spec_unit']);
        $ids   = $bom->lines->pluck('child_item_idx')->merge($bom->lines->flatMap(fn ($l) => $l->substitutes->pluck('child_item_idx')));
        $stock = DB::table('stock_balance')->whereIn('item_idx', $ids)
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx');
        $row = fn ($l, $item) => [
            'child_item_idx' => $l->child_item_idx,
            'item_code'      => $item?->item_code,
            'item_name'      => $item?->item_name,
            'item_spec'      => $item?->spec,
            'item_material'  => $item?->material,
            'spec_unit'      => $item?->spec_unit,
            'qty_per'        => (float) $l->qty,
            'yield_qty'      => $l->yield_qty !== null ? (float) $l->yield_qty : null,   // 생산 가능 수량 ( 있으면 소요 = 올림( 수량 ÷ N ) )
            'stock_qty'      => (float) ($stock[$l->child_item_idx] ?? 0),
        ];
        return [
            'bom_idx' => $bom->bom_idx,
            'version' => $bom->owner_type === Bom::OWNER_ORDER_LINE ? '수주 자재' : $bom->version,
            'lines'   => $bom->lines->map(fn ($l) => $row($l, $l->childItem) + [
                'routing_line_idx' => $l->routing_line_idx,
                'substitutes'      => $l->substitutes->map(fn ($s) => $row($s, $s->childItem))->values(),
            ])->values(),
        ];
    }

    private function soLineRow(SalesOrderLine $l, ?int $exceptWo): array {
        return [
            'sales_order_line_idx' => $l->sales_order_line_idx,
            'item_idx'             => $l->item_idx,
            'item_label'           => $l->item ? \MesCore\Config\Models\Item::labelOf($l->item) : null,
            'order_no'             => $l->order?->order_no,
            'line_no'              => $l->line_no,
            'partner_name'         => $l->order?->partner?->partner_name,
            'due_date'             => $l->due_date?->toDateString(),
            'qty'                  => (float) $l->qty,
            'remaining'            => $this->orders->remainingQty($l, $exceptWo),
            // 수주 공정 순서 — 같은 지시에는 이것이 같은 줄끼리만 담는다
            'routing_key'          => $this->routingSvc->orderLineProcessKeys([$l->sales_order_line_idx])[$l->sales_order_line_idx] ?? '',
            // 수주 자재 구성 — 같은 지시에는 이것도 같은 줄끼리만
            'material_key'         => $this->bomSvc->orderLineMaterialKeys([$l->sales_order_line_idx])[$l->sales_order_line_idx] ?? '',
        ];
    }

    private function toForm(WorkOrder $w): array {
        $assigns = WorkOrderService::opAssignments($w->ops);   // 공정 「설비 - 작업자」 묶음 ( 11-25 )
        $stock = DB::table('stock_balance')->whereIn('item_idx', $w->materials->pluck('child_item_idx')->merge($w->materials->flatMap(fn ($m) => $m->substitutes->pluck('child_item_idx'))))
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx');
        $held  = app(MaterialIssueService::class)->heldByItem($w->work_order_idx);
        return [
            'work_order_idx' => $w->work_order_idx,
            'wo_no'          => $w->wo_no,
            'source'         => $w->source,
            'production_plan_idx' => $w->production_plan_idx,
            'plan_no'        => $w->production_plan_idx ? \MesCore\Production\Models\ProductionPlan::whereKey($w->production_plan_idx)->value('plan_no') : null,
            'item_idx'       => $w->item_idx,
            'item_code'      => $w->item?->item_code,
            'item_name'      => $w->item?->item_name,
            'item_spec'      => $w->item?->spec,
            'item_material'  => $w->item?->material,
            'item_kind'      => $w->item?->item_kind,   // 반제품 줄 표시
            // 반제품 지시의 부모 ( 어느 완제품 지시용 — 이력 )
            'parent_work_order_idx' => $w->parent_work_order_idx,
            'parent_wo_no'   => $w->parent?->wo_no,
            'parent_item'    => $w->parent?->item ? \MesCore\Config\Models\Item::labelOf($w->parent->item) : null,
            'spec_unit'      => $w->item?->spec_unit,
            'routing_idx'    => $w->routing_idx,
            'bom_idx'        => $w->bom_idx,
            'lot_no'         => $w->isRunLot() ? '실적별' : $w->lot?->lot_no,   // RUN 방식 = 실적마다 LOT
            'lot_rule_idx'   => $w->lot_rule_idx,
            'target_qty'     => (float) $w->target_qty,
            'scan_qty'       => (float) $w->scan_qty,
            'start_date'     => $w->start_date?->toDateString(),
            'due_date'       => $w->due_date?->toDateString(),
            'status'         => $w->status,
            'remark'         => $w->remark,
            'ops'            => $w->ops->map(function (WorkOrderOp $o) use ($assigns) { $assign = $assigns[$o->work_order_op_idx] ?? ['equipments' => [], 'workers' => []]; return [
                'work_order_op_idx' => $o->work_order_op_idx,
                'seq'               => $o->seq,
                'process_idx'       => $o->process_idx,
                'routing_line_idx'  => $o->routing_line_idx,
                'source_routing_line_idx' => $o->routing_line_idx ? RoutingLine::whereKey($o->routing_line_idx)->value('source_routing_line_idx') : null,
                'equipment_idx'     => $o->equipment_idx,
                'worker_idx'        => $o->worker_idx,
                'crews'             => $assign['crews'],   // 11-25 : 「설비 - 작업자」 묶음 ( 첫 묶음 = 대표 )
                'cycle_time_sec'    => (float) $o->cycle_time_sec,
                'due_date'          => $o->due_date?->toDateString(),
                'insp_first'        => $o->insp_first,
                'insp_middle'       => $o->insp_middle,
                'insp_last'         => $o->insp_last,
                'pre_approve_min'   => $o->pre_approve_min,
                'status'            => $o->status,
                'remark'            => $o->remark,
            ]; })->values(),
            'materials'      => $w->materials->map(fn ($m) => [
                'is_extra'          => $m->isExtra(),   // BOM 외 자재 ( 출고 창에서 추가 · 11-13 )
                'child_item_idx'    => $m->child_item_idx,
                'item_code'         => $m->childItem?->item_code,
                'item_name'         => $m->childItem?->item_name,
                'item_spec'         => $m->childItem?->spec,
                'item_material'     => $m->childItem?->material,
                'spec_unit'         => $m->childItem?->spec_unit,
                'qty_per'           => (float) $m->qty_per,
                'yield_qty'         => $m->yield_qty !== null ? (float) $m->yield_qty : null,
                'required_qty'      => (float) $m->required_qty,
                'work_order_op_idx' => $m->work_order_op_idx,
                'stock_qty'         => (float) ($stock[$m->child_item_idx] ?? 0),
                'issued_qty'        => (float) $m->issued_qty,
                'consumed_qty'      => $held[$m->child_item_idx]['consumed'] ?? 0.0,
                'held_qty'          => $held[$m->child_item_idx]['qty'] ?? 0.0,
                // 대체 자재 ( 자기 소요 · 출고 )
                'substitutes'       => $m->substitutes->map(fn ($s) => [
                    'child_item_idx' => $s->child_item_idx,
                    'item_code'      => $s->childItem?->item_code,
                    'item_name'      => $s->childItem?->item_name,
                    'item_spec'      => $s->childItem?->spec,
                    'spec_unit'      => $s->childItem?->spec_unit,
                    'qty_per'        => (float) $s->qty_per,
                    'yield_qty'      => $s->yield_qty !== null ? (float) $s->yield_qty : null,
                    'required_qty'   => (float) $s->required_qty,
                    'stock_qty'      => (float) ($stock[$s->child_item_idx] ?? 0),
                    'issued_qty'     => (float) $s->issued_qty,
                ])->values(),
            ])->values(),
            // 병합 이력 : 수주 줄마다 담은 수량 · 추가한 사람 · 일시
            'sources'        => $w->sources->map(fn (WorkOrderSource $s) => $this->soLineRow($s->salesOrderLine, $w->work_order_idx) + [
                'qty_in'   => (float) $s->qty,
                'added_by' => $s->creator?->user_name ?: $s->creator?->user_id,
                'added_at' => $s->create_datetime?->format('Y-m-d H:i'),
            ])->values(),
        ];
    }

    // 공정흐름표 ( 11-26 · 문서 양식 WO_FLOW ) — 고른 작업지시서( sheet_idx )의 모든 작업지시 · 작업지시 하나 = 한 장
    public function flowSheets(\Illuminate\Http\Request $request, \MesCore\Production\Services\WoFlowSheetService $flow, \MesCore\Config\Services\SiteConfigService $site) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $docs = \MesCore\Production\Models\WorkOrder::with($flow::RELATIONS)->whereIn('sheet_idx', $v['ids'])
            ->where('status', '!=', 'CANCELED')->orderBy('wo_no')->get()
            ->map(fn ($wo) => $flow->build($wo))->values();
        return response()->json(['docs' => $docs]);   // 고정 배치 ( WoFlowPages · 11-26 — 문서 양식에서 뺐다 )
    }
}

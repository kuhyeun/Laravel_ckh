<?php

namespace MesCore\Config\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingChangeLog;
use MesCore\Config\Models\RoutingLine;
use MesCore\Order\Models\SalesOrderLine;

// 공정 라우팅 3단계 ( docs/라우팅-3단계-설계.md ) — 공정 줄 검증 · 동기화 · 복사 · 변경 이력을 한곳에
//   라우팅 관리( ① 틀 ) · 품목( ② ) · 수주( ③ ) · 이관이 같이 쓴다
//   줄 형태 : [{ routing_line_idx?, source_routing_line_idx?, process_idx, cycle_time_sec, insp_first, insp_middle, insp_last,
//              pre_approve_min, pre_approved_by_design, remark? }] — 배열 순서가 곧 seq
class RoutingService {

    // 이력에서 값 변경( UPDATE )으로 보는 칸 ( 작업지시는 공정 구성 + 표준시간 · 검사만 — 설비 · 작업자 · 기한은 남기지 않는다 )
    private const TRACKED    = ['cycle_time_sec', 'insp_first', 'insp_middle', 'insp_last', 'pre_approve_min', 'pre_approved_by_design'];
    public const WO_TRACKED  = ['cycle_time_sec', 'insp_first', 'insp_middle', 'insp_last'];

    public const LINE_ATTRIBUTES = [
        'process_idx'     => '공정',
        'cycle_time_sec'  => '개당 표준시간',
        'pre_approve_min' => '선행 진행 승인 수',
    ];

    // 줄 검증 규칙 ( $prefix = 요청의 배열 이름 )
    public static function lineRules(string $prefix = 'lines'): array {
        return [
            "{$prefix}.*.routing_line_idx"        => 'nullable|integer',
            "{$prefix}.*.source_routing_line_idx" => 'nullable|integer',
            "{$prefix}.*.process_idx"             => 'required|integer|exists:process,process_idx',
            "{$prefix}.*.cycle_time_sec"          => 'nullable|numeric|min:0|max:9999999',
            "{$prefix}.*.insp_first"              => 'required|in:Y,N',
            "{$prefix}.*.insp_middle"             => 'required|in:Y,N',
            "{$prefix}.*.insp_last"               => 'required|in:Y,N',
            "{$prefix}.*.pre_approve_min"         => 'nullable|integer|min:0|max:20',
            "{$prefix}.*.pre_approved_by_design"  => 'required|in:Y,N',
            "{$prefix}.*.remark"                  => 'nullable|string|max:2000',
        ];
    }

    public static function lineAttributes(string $prefix = 'lines'): array {
        return collect(self::LINE_ATTRIBUTES)->mapWithKeys(fn ($v, $k) => ["{$prefix}.*.{$k}" => $v])->all();
    }

    /**
     * 업무 규칙 검사 ( 규칙으로 못 거르는 것 ) → 실패는 ValidationException · 통과하면 경고 목록
     *   $current : 고치는 라우팅 ( 이미 쓰던 공정은 미사용이어도 유지 허용 )
     */
    public function assertLines(array $lines, ?Routing $current, string $prefix = 'lines', bool $allowEmpty = false): array {
        if (!$lines && !$allowEmpty) {
            // E_ROUTING_EMPTY : 공정 0건
            throw ValidationException::withMessages([$prefix => ['공정을 1개 이상 등록하세요.']]);
        }
        if (!$lines) {
            return [];
        }

        $processes       = Process::whereIn('process_idx', collect($lines)->pluck('process_idx'))->get()->keyBy('process_idx');
        $currentProcIdxs = $current?->exists
            ? RoutingLine::where('routing_idx', $current->routing_idx)->pluck('process_idx')->flip()
            : collect();
        $errors = [];

        foreach ($lines as $i => $line) {
            $process = $processes->get($line['process_idx']);

            // 새로 고르는 공정은 사용 중이어야 함 ( 기존 줄이 미사용으로 내려간 경우는 유지 허용 )
            if ($process && $process->is_use === 'N' && !$currentProcIdxs->has($line['process_idx'])) {
                $errors["{$prefix}.{$i}.process_idx"] = ['사용 중인 공정을 선택하세요.'];
            }

            $isInspection = $process && $process->process_type === Process::TYPE_INSPECTION;
            $anyInsp      = collect(['insp_first', 'insp_middle', 'insp_last'])->contains(fn ($k) => $line[$k] === 'Y');

            // E_IFL_NONE : 검사 공정인데 초·중·종을 셋 다 끔
            if ($isInspection && !$anyInsp) {
                $errors["{$prefix}.{$i}.insp"] = ['초 · 중 · 종 중 하나 이상을 켜세요.'];
            }

            $preMin    = (int) ($line['pre_approve_min'] ?? 0);
            $preDesign = $line['pre_approved_by_design'] === 'Y';

            // E_PRE_APPROVE_BOTH : 승인 N명과 설계 승인을 둘 다 켬
            if ($preMin > 0 && $preDesign) {
                $errors["{$prefix}.{$i}.pre_approve"] = ['승인 N명과 설계 승인 중 하나만 고르세요.'];
            }
            // E_PRE_APPROVE_NO_LAST : 종물이 꺼져 있는데 선행 진행을 켬 ( 건너뛸 것이 없음 )
            if (($preMin > 0 || $preDesign) && $line['insp_last'] !== 'Y') {
                $errors["{$prefix}.{$i}.pre_approve"] = ['종물 검사가 꺼져 있어 선행 진행을 설정할 수 없습니다.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 경고 ( 막지 않고 알림 )
        $warnings  = [];
        $firstProc = $processes->get($lines[0]['process_idx']);
        if ($firstProc && $firstProc->process_type === Process::TYPE_INSPECTION) {
            // W_ROUTING_INSP_FIRST : 검사 공정이 첫 줄 ( 앞에 만들 것이 없음 )
            $warnings[] = '첫 줄이 검사 공정입니다. 앞에 만들 것이 없습니다.';
        }
        if (collect($lines)->contains(fn ($l) => $l['pre_approved_by_design'] === 'Y')) {
            // W_PRE_APPROVE_DESIGN : 검사 없이 나가는 길을 상시로 여는 것
            $warnings[] = '설계 승인은 종물 검사 없이 다음 공정으로 나가는 길을 상시로 엽니다.';
        }
        return $warnings;
    }

    /**
     * 공정 줄 동기화 → 경고 목록
     *   기존 줄( routing_line_idx )은 갱신만 한다 → BOM 투입 지점 참조가 순서 변경에도 유지됨
     *   $log  : [ scope, ref_idx ] 를 주면 변경 이력을 남긴다
     *   $base : 처음 만들거나 원본을 바꿔 다시 불러온 경우의 비교 대상( 원본 라우팅 ) — 줄은 source_routing_line_idx 로 맞춘다
     */
    public function syncLines(Routing $routing, array $lines, ?array $log = null, ?Routing $base = null): array {
        $existing = RoutingLine::where('routing_idx', $routing->routing_idx)->get()->keyBy('routing_line_idx');
        $keep     = [];

        // 비교 기준 ( 이력 ) : 원본과 비교하면 원본 줄 IDX, 아니면 이 라우팅의 줄 IDX 가 같은 줄의 열쇠
        $before = $base
            ? $base->lines()->get()->map(fn ($l) => $this->snapshot($l, $l->routing_line_idx))->all()
            : $existing->sortBy('seq')->map(fn ($l) => $this->snapshot($l, $l->routing_line_idx))->values()->all();
        $after = [];

        foreach (array_values($lines) as $order => $row) {
            $line = (!empty($row['routing_line_idx']) && $existing->has($row['routing_line_idx']))
                ? $existing->get($row['routing_line_idx'])
                : new RoutingLine([
                    'routing_idx'             => $routing->routing_idx,
                    'source_routing_line_idx' => ($row['source_routing_line_idx'] ?? null) ?: null,
                ]);

            $wasDesign = $line->exists && $line->pre_approved_by_design === 'Y';
            $isDesign  = $row['pre_approved_by_design'] === 'Y';

            $line->fill([
                'seq'                    => $order + 1,
                'process_idx'            => $row['process_idx'],
                'cycle_time_sec'         => $row['cycle_time_sec'] ?? 0,
                'insp_first'             => $row['insp_first'],
                'insp_middle'            => $row['insp_middle'],
                'insp_last'              => $row['insp_last'],
                'pre_approve_min'        => $row['pre_approve_min'] ?? 0,
                'pre_approved_by_design' => $row['pre_approved_by_design'],
                'remark'                 => $row['remark'] ?? null,
            ]);

            // 설계 승인은 "매번 묻지 않겠다"는 선언 → 켠 사람과 시각을 남긴다 ( 끄면 지운다 )
            if ($isDesign && !$wasDesign) {
                $line->pre_approved_by = Auth::id();
                $line->pre_approved_at = now();
            } elseif (!$isDesign) {
                $line->pre_approved_by = null;
                $line->pre_approved_at = null;
            }

            $line->save();
            $keep[]  = $line->routing_line_idx;
            $after[] = $this->snapshot($line, $base ? $line->source_routing_line_idx : $line->routing_line_idx);
        }

        // 지워질 줄 ( 화면에서 뺀 줄 )
        $removedIdxs = RoutingLine::where('routing_idx', $routing->routing_idx)
            ->whereNotIn('routing_line_idx', $keep ?: [0])
            ->pluck('routing_line_idx');

        // W_ROUTING_LINE_IN_BOM : 그 줄을 투입 지점으로 쓰는 BOM 라인이 있으면 알린다
        //   막지 않는다 — FK 가 NULL 로 만들고, BOM 화면에서 다시 지정한다
        $warnings = [];
        if ($removedIdxs->isNotEmpty()) {
            $affected = DB::table('bom_line')
                ->join('bom', 'bom.bom_idx', '=', 'bom_line.bom_idx')
                ->join('item', 'item.item_idx', '=', 'bom.item_idx')
                ->whereIn('bom_line.routing_line_idx', $removedIdxs)
                ->select('item.item_name', 'bom.version')
                ->get();

            if ($affected->isNotEmpty()) {
                $names = $affected->map(fn ($r) => $r->item_name . ' ' . $r->version)->unique()->take(3)->implode(', ');
                $more  = $affected->count() > 3 ? ' 외' : '';
                $warnings[] = "삭제한 공정 줄을 쓰던 BOM {$affected->count()}건( {$names}{$more} )의 투입 지점이 비워졌습니다. BOM관리에서 다시 지정하세요.";
            }
        }

        RoutingLine::whereIn('routing_line_idx', $removedIdxs)->delete();

        if ($log) {
            $this->logDiff($log[0], (int) $log[1], $routing->routing_idx, $before, $after);
        }

        return $warnings;
    }

    // 화면 · 응답용 줄 ( BOM 참조 수 포함 — 줄을 지우기 전에 화면에서 알 수 있도록 )
    public function linesPayload(Routing $routing): array {
        $lines = $routing->lines()->with('process')->get();

        $bomRefs = DB::table('bom_line')
            ->whereIn('routing_line_idx', $lines->pluck('routing_line_idx'))
            ->selectRaw('routing_line_idx, COUNT(*) c')
            ->groupBy('routing_line_idx')
            ->pluck('c', 'routing_line_idx');

        return $lines->map(fn (RoutingLine $l) => [
            'routing_line_idx'        => $l->routing_line_idx,
            'source_routing_line_idx' => $l->source_routing_line_idx,
            'seq'                     => $l->seq,
            'process_idx'             => $l->process_idx,
            'process_code'            => $l->process?->process_code,
            'process_name'            => $l->process?->process_name,
            'process_type'            => $l->process?->process_type,
            'process_class_code'      => $l->process?->class_code,
            'process_is_use'          => $l->process?->is_use,
            'cycle_time_sec'          => $l->cycle_time_sec,
            'insp_first'              => $l->insp_first,
            'insp_middle'             => $l->insp_middle,
            'insp_last'               => $l->insp_last,
            'pre_approve_min'         => $l->pre_approve_min,
            'pre_approved_by_design'  => $l->pre_approved_by_design,
            'pre_approved_at'         => $l->pre_approved_at?->format('Y-m-d H:i'),
            'bom_ref_count'           => (int) ($bomRefs[$l->routing_line_idx] ?? 0),
            'remark'                  => $l->remark,
        ])->values()->all();
    }

    // 원본 라우팅의 줄을 "복사할 새 줄"로 ( 화면 편집기 · 엑셀 등록 ) — routing_line_idx 는 비우고 원본 줄을 source 로
    public function copyRows(Routing $from): array {
        return collect($this->linesPayload($from))->map(fn ($l) => array_merge($l, [
            'routing_line_idx'        => null,
            'source_routing_line_idx' => $l['routing_line_idx'],
            'bom_ref_count'           => 0,
            'pre_approved_at'         => null,
        ]))->all();
    }

    // 공정 선택지 ( 사용 중 · 시스템 공정 먼저 ) — 라우팅 관리 · 품목 · 수주 편집기
    public function processOptions(): Collection {
        return Process::select('process_idx', 'process_code', 'process_name', 'process_type', 'class_code', 'is_system', 'system_key')
            ->where('is_use', 'Y')
            ->orderByRaw("CASE WHEN is_system = 'Y' THEN 0 ELSE 1 END")
            ->orderBy('process_code')
            ->get();
    }

    // ---- ② 품목 라우팅 ----

    public function itemRouting(int $itemIdx): ?Routing {
        return Routing::where('owner_type', Routing::OWNER_ITEM)->where('item_idx', $itemIdx)->first();
    }

    /**
     * 품목 저장 뒤 ② 맞추기 → 경고 목록
     *   $templateIdx : 고른 틀 ( 없으면 null )
     *   $lines       : 화면이 보낸 공정 줄 / null = 엑셀 등록 — 틀이 그대로면 손대지 않고, 바뀌었거나 ② 가 없으면 틀을 복사
     *   제품이 아니면 ② 를 미사용으로 · 품목 사용 여부를 ② 에도
     */
    public function syncItemRouting(Item $item, ?int $templateIdx, ?array $lines): array {
        $own = $this->itemRouting($item->item_idx);

        if ($item->item_type !== 'PRODUCT') {
            if ($own && $own->is_use === 'Y') {
                $own->fill(['is_use' => 'N'])->save();
            }
            $this->pointItem($item, null);
            return [];
        }

        $baseChanged = $own && (int) $own->base_routing_idx !== (int) $templateIdx;
        $template    = $templateIdx ? Routing::templates()->find($templateIdx) : null;

        if ($lines === null) {
            if ($own && !$baseChanged) {
                $this->setUse($own, $item);
                $this->pointItem($item, $own->lines()->exists() ? $own->routing_idx : null);
                return [];
            }
            $lines = $template ? $this->copyRows($template) : [];
        }

        // 공정이 하나도 없으면 라우팅 없음 ( 있던 ② 는 줄을 비우고 미사용 — 이력은 남긴다 )
        if (!$lines) {
            $warnings = [];
            if ($own) {
                $warnings = $this->syncLines($own, [], [RoutingChangeLog::SCOPE_ITEM, $item->item_idx]);
                $own->fill(['base_routing_idx' => $templateIdx, 'is_use' => 'N'])->save();
            }
            $this->pointItem($item, null);
            return $warnings;
        }

        $isNew   = !$own;
        $oldBase = $own?->base_routing_idx;
        $own ??= new Routing([
            'owner_type'   => Routing::OWNER_ITEM,
            'item_idx'     => $item->item_idx,
            'routing_code' => 'IT-' . $item->item_idx,
        ]);
        $own->fill([
            'base_routing_idx' => $templateIdx,
            'routing_name'     => mb_substr($item->item_name, 0, 60),
            'is_use'           => $item->is_use,
        ])->save();

        $log = [RoutingChangeLog::SCOPE_ITEM, $item->item_idx];
        if ($isNew || $baseChanged) {
            // 처음 · 틀을 바꿈 : 공정 목록 통째로 남기고, 틀과 다른 점은 ADD · REMOVE … 로
            $this->logWhole($isNew ? 'CREATE' : 'RELOAD', $log[0], $log[1], $own->routing_idx, $isNew ? null : $own, $oldBase, $lines, $templateIdx);
        }
        $warnings = $this->syncLines($own, $lines, $log, ($isNew || $baseChanged) ? $template : null);

        $this->pointItem($item, $own->routing_idx);
        return $warnings;
    }

    // ---- ① 틀 → ② 품목 라우팅 일괄 반영 ( 11-13 · 회의 '라우팅 일괄 적용' ) ----

    /**
     * 이 틀에서 복사한 품목 라우팅( ② ) 목록 — 사용 중인 제품만
     *   differs : 지금 틀과 다른가 ( 틀을 고친 뒤면 전부 다르다 )
     * @return array<int, array{routing_idx:int, item_idx:int, item_code:string, item_name:string, spec:?string, differs:bool, line_count:int}>
     */
    public function derivedItemRoutings(Routing $template): array {
        return Routing::where('owner_type', Routing::OWNER_ITEM)->where('base_routing_idx', $template->routing_idx)
            ->join('item', 'item.item_idx', '=', 'routing.item_idx')
            ->where('item.is_use', 'Y')->where('item.item_type', 'PRODUCT')
            ->orderBy('item.item_code')
            ->get(['routing.*', 'item.item_code', 'item.item_name', 'item.spec'])
            ->map(fn (Routing $r) => [
                'routing_idx' => (int) $r->routing_idx,
                'item_idx'    => (int) $r->item_idx,
                'item_code'   => $r->item_code,
                'item_name'   => $r->item_name,
                'spec'        => $r->spec,
                'differs'     => $this->differsFromBase($r),
                'line_count'  => $r->lines()->count(),
            ])->values()->all();
    }

    /**
     * 틀의 공정 구성을 고른 품목의 품목 라우팅( ② )에 그대로 — 품목에서 따로 고친 공정은 틀 값으로 덮인다
     *   같은 원본 줄( source_routing_line_idx )에서 온 품목 줄은 그 줄을 갱신만 한다 → BOM 투입 지점이 유지된다
     *   이미 등록된 수주( ③ ) · 작업지시는 건드리지 않는다 · 변경 이력( 품목 )을 남긴다
     * @return array{applied:int, warnings:string[]}
     */
    public function applyTemplateToItems(Routing $template, array $itemIdxs): array {
        $rows = $this->copyRows($template);
        $owns = Routing::where('owner_type', Routing::OWNER_ITEM)->where('base_routing_idx', $template->routing_idx)
            ->whereIn('item_idx', $itemIdxs)->get();
        $warnings = [];
        foreach ($owns as $own) {
            // 원본 줄 → 품목의 기존 줄 ( 같은 원본에서 온 줄이 여럿이면 순서대로 하나씩 )
            $bySource = RoutingLine::where('routing_idx', $own->routing_idx)->whereNotNull('source_routing_line_idx')
                ->orderBy('seq')->get()->groupBy('source_routing_line_idx')->map(fn ($g) => $g->pluck('routing_line_idx')->all())->all();
            $lines = array_map(function ($row) use (&$bySource) {
                $src = $row['source_routing_line_idx'];
                $row['routing_line_idx'] = !empty($bySource[$src]) ? array_shift($bySource[$src]) : null;
                return $row;
            }, $rows);
            $warnings = array_merge($warnings, $this->syncLines($own, $lines, [RoutingChangeLog::SCOPE_ITEM, $own->item_idx]));
            if ($own->is_use !== 'Y' && $lines) {
                $own->fill(['is_use' => 'Y'])->save();
            }
            DB::table('item')->where('item_idx', $own->item_idx)->update(['routing_idx' => $lines ? $own->routing_idx : null]);
        }
        if ($owns->isNotEmpty()) {
            Item::refreshCycleTime($owns->pluck('item_idx')->all());
        }
        return ['applied' => $owns->count(), 'warnings' => array_values(array_unique($warnings))];
    }

    // ---- ③ 수주 라우팅 ----

    public function orderLineRouting(int $lineIdx): ?Routing {
        return Routing::where('owner_type', Routing::OWNER_ORDER_LINE)->where('sales_order_line_idx', $lineIdx)->first();
    }

    // 품목의 품목 라우팅( ② ) — 수주 줄 공정의 원본
    public function itemRoutingOf(?int $itemIdx): ?Routing {
        $routingIdx = $itemIdx ? Item::whereKey($itemIdx)->value('routing_idx') : null;
        return $routingIdx ? Routing::find($routingIdx) : null;
    }

    /**
     * 수주 줄 저장 뒤 ③ 맞추기 → 경고 목록 ( 줄마다 항상 만든다 — 작업지시와 비교할 고정 기준 )
     *   $lines : 화면이 보낸 공정 / null = 손대지 않음 ( ③ 이 없거나 품목을 바꿨으면 품목 라우팅을 복사 )
     *   잠금( 작업지시 생성 )은 부르는 쪽( SalesOrderService )이 먼저 막는다
     */
    public function syncOrderLineRouting(SalesOrderLine $line, string $orderNo, ?array $lines): array {
        $own         = $this->orderLineRouting($line->sales_order_line_idx);
        $base        = $this->itemRoutingOf((int) $line->item_idx);
        $baseIdx     = $base?->routing_idx;
        $baseChanged = $own && (int) $own->base_routing_idx !== (int) $baseIdx;

        if ($lines === null) {
            if ($own && !$baseChanged) {
                return [];
            }
            $lines = $base ? $this->copyRows($base) : [];
        }

        $isNew   = !$own;
        $oldBase = $own?->base_routing_idx;
        $own ??= new Routing([
            'owner_type'           => Routing::OWNER_ORDER_LINE,
            'sales_order_line_idx' => $line->sales_order_line_idx,
            'routing_code'         => 'SO-' . $line->sales_order_line_idx,
        ]);
        $own->fill([
            'base_routing_idx' => $baseIdx,
            'routing_name'     => mb_substr("{$orderNo} #{$line->line_no}", 0, 60),
            'is_use'           => 'Y',
        ])->save();

        $log = [RoutingChangeLog::SCOPE_ORDER_LINE, $line->sales_order_line_idx];
        if ($isNew || $baseChanged) {
            // 처음 · 품목을 바꿈 : 공정 목록 통째 + 품목 라우팅과 다른 점( 수주에서 바꾼 공정 )
            $this->logWhole($isNew ? 'CREATE' : 'RELOAD', $log[0], $log[1], $own->routing_idx, $isNew ? null : $own, $oldBase, $lines, $baseIdx);
        }
        return $this->syncLines($own, $lines, $log, ($isNew || $baseChanged) ? $base : null);
    }

    // 수주 줄들의 공정 순서 ( 같은 지시에 담을 수 있는지 — 공정 순서가 같아야 한다 ) [ 줄 IDX => "2-6-4" ]
    public function orderLineProcessKeys(array $lineIdxs): array {
        if (!$lineIdxs) {
            return [];
        }
        return Routing::where('owner_type', Routing::OWNER_ORDER_LINE)->whereIn('sales_order_line_idx', $lineIdxs)
            ->with('lines:routing_line_idx,routing_idx,seq,process_idx')->get()
            ->mapWithKeys(fn (Routing $r) => [$r->sales_order_line_idx => $r->lines->pluck('process_idx')->implode('-')])
            ->all();
    }

    /**
     * ③ 이 원본( ② )과 다른가 — 수주에서 공정을 고쳤는지 ( 화면의 "수정됨" )
     *   공정 순서 · 공정 · 표준시간 · 검사 · 선행 승인 중 하나라도 다르면 true
     */
    public function differsFromBase(Routing $own): bool {
        $mine = $own->lines()->get();
        $base = $own->base_routing_idx ? RoutingLine::where('routing_idx', $own->base_routing_idx)->orderBy('seq')->get() : collect();
        if ($mine->count() !== $base->count()) {
            return true;
        }
        foreach ($mine->values() as $i => $l) {
            $b = $base->values()[$i];
            if ((int) $l->source_routing_line_idx !== (int) $b->routing_line_idx || (int) $l->process_idx !== (int) $b->process_idx) {
                return true;
            }
            foreach (self::TRACKED as $k) {
                if ($l->{$k} != $b->{$k}) {
                    return true;
                }
            }
        }
        return false;
    }

    // 품목 미사용 · 다시 사용 → ② 도 같이
    public function setUse(Routing $own, Item $item): void {
        $use = $item->is_use === 'Y' && $item->item_type === 'PRODUCT' ? 'Y' : 'N';
        if ($own->is_use !== $use) {
            $own->fill(['is_use' => $use])->save();
        }
    }

    // item.routing_idx 를 ② 로 ( 모델 이벤트 없이 — 품목은 방금 저장됐다 ) + 1개 생산 표준시간
    private function pointItem(Item $item, ?int $routingIdx): void {
        if ((string) $item->routing_idx !== (string) $routingIdx) {
            DB::table('item')->where('item_idx', $item->item_idx)->update(['routing_idx' => $routingIdx]);
            $item->routing_idx = $routingIdx;
        }
        Item::refreshCycleTime([$item->item_idx]);
    }

    // ---- 이력 ----

    // 비교용 한 줄 ( 라우팅 줄 · 작업지시 공정 둘 다 — $seq 를 주면 그 순서로 )
    public function snapshot(object $l, ?int $key, array $tracked = self::TRACKED, ?int $seq = null): array {
        return [
            'key'         => $key,
            'seq'         => $seq ?? (int) $l->seq,
            'process_idx' => (int) $l->process_idx,
            'values'      => collect($tracked)->mapWithKeys(fn ($k) => [$k => $k === 'cycle_time_sec' ? (float) $l->{$k} : $l->{$k}])->all(),
        ];
    }

    // 공정 목록 통째 ( CREATE · RELOAD )
    public function logWhole(string $action, string $scope, int $refIdx, ?int $routingIdx, ?Routing $beforeRouting, ?int $beforeBase, array $afterRows, ?int $baseIdx): void {
        $summary = fn ($rows) => collect($rows)->values()->map(fn ($r, $i) => ['seq' => $i + 1, 'process_idx' => (int) $r['process_idx']])->all();
        RoutingChangeLog::create([
            'scope'       => $scope,
            'ref_idx'     => $refIdx,
            'routing_idx' => $routingIdx,
            'action'      => $action,
            'before_json' => $beforeRouting ? ['base_routing_idx' => $beforeBase, 'lines' => $summary($beforeRouting->lines()->get()->toArray())] : null,
            'after_json'  => ['base_routing_idx' => $baseIdx, 'lines' => $summary($afterRows)],
        ]);
    }

    /**
     * 줄 단위 비교 → ADD · REMOVE · MOVE · UPDATE
     *   같은 줄 = 같은 key ( key 가 없는 줄은 새로 더한 줄 )
     *   MOVE : 남은 줄끼리의 앞뒤가 바뀐 줄만 ( 앞에 줄이 더해져 번호만 밀린 것은 아님 ) — 가장 긴 증가 부분 수열 밖의 줄
     */
    public function logDiff(string $scope, int $refIdx, ?int $routingIdx, array $before, array $after, array $tracked = self::TRACKED): void {
        $rows    = [];
        $byKey   = collect($before)->filter(fn ($b) => $b['key'] !== null)->keyBy('key');
        $kept    = [];

        foreach ($after as $a) {
            $b = $a['key'] !== null ? $byKey->get($a['key']) : null;
            if (!$b) {
                $rows[] = ['action' => 'ADD', 'process_idx' => $a['process_idx'], 'seq_after' => $a['seq'], 'after_json' => $a['values']];
                continue;
            }
            $kept[] = [$a, $b];
            $changed = array_values(array_filter($tracked, fn ($k) => $a['values'][$k] != $b['values'][$k]));
            if ($a['process_idx'] !== $b['process_idx']) {
                // 공정을 바꾼 줄 = 뺀 것 + 더한 것
                $rows[] = ['action' => 'REMOVE', 'process_idx' => $b['process_idx'], 'seq_before' => $b['seq'], 'before_json' => $b['values']];
                $rows[] = ['action' => 'ADD', 'process_idx' => $a['process_idx'], 'seq_after' => $a['seq'], 'after_json' => $a['values']];
            } elseif ($changed) {
                $rows[] = [
                    'action'      => 'UPDATE', 'process_idx' => $a['process_idx'], 'seq_before' => $b['seq'], 'seq_after' => $a['seq'],
                    'before_json' => array_intersect_key($b['values'], array_flip($changed)),
                    'after_json'  => array_intersect_key($a['values'], array_flip($changed)),
                ];
            }
        }

        $afterKeys = collect($after)->pluck('key')->filter(fn ($k) => $k !== null)->flip();
        foreach ($before as $b) {
            if ($b['key'] === null || !$afterKeys->has($b['key'])) {
                $rows[] = ['action' => 'REMOVE', 'process_idx' => $b['process_idx'], 'seq_before' => $b['seq'], 'before_json' => $b['values']];
            }
        }

        // 순서 : 남은 줄( 같은 공정 )을 새 순서로 늘어놓았을 때 옛 순서가 증가하는 가장 긴 줄기 밖이면 옮긴 줄
        $kept   = array_values(array_filter($kept, fn ($p) => $p[0]['process_idx'] === $p[1]['process_idx']));
        $stay   = $this->longestIncreasing(array_map(fn ($p) => $p[1]['seq'], $kept));
        foreach ($kept as $i => [$a, $b]) {
            if (!isset($stay[$i])) {
                $rows[] = ['action' => 'MOVE', 'process_idx' => $a['process_idx'], 'seq_before' => $b['seq'], 'seq_after' => $a['seq']];
            }
        }

        foreach ($rows as $r) {
            RoutingChangeLog::create($r + ['scope' => $scope, 'ref_idx' => $refIdx, 'routing_idx' => $routingIdx]);
        }
    }

    // 가장 긴 증가 부분 수열의 위치 ( [ 위치 => true ] )
    private function longestIncreasing(array $seq): array {
        $n = count($seq);
        if (!$n) {
            return [];
        }
        $len  = array_fill(0, $n, 1);
        $prev = array_fill(0, $n, -1);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $i; $j++) {
                if ($seq[$j] < $seq[$i] && $len[$j] + 1 > $len[$i]) {
                    $len[$i]  = $len[$j] + 1;
                    $prev[$i] = $j;
                }
            }
        }
        $end = array_keys($len, max($len))[0];
        $out = [];
        for ($k = $end; $k !== -1; $k = $prev[$k]) {
            $out[$k] = true;
        }
        return $out;
    }
}

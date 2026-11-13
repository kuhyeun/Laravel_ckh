<?php

namespace MesCore\Config\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomChangeLog;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\BomLineSubstitute;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingLine;
use MesCore\Order\Models\SalesOrderLine;

// BOM 자재 줄 · 대체 자재 — 검증 · 동기화 · 복사 · 순환 검사를 한곳에 ( docs/대체자재-수주자재-설계.md )
//   BOM관리 · 품목( 주 자재 ) · 수주( 수주 자재 — 2단계 )가 같이 쓴다
//   줄 형태 : [{ bom_line_idx?, child_item_idx, routing_line_idx?, qty, calc_code?, ext_n01?, remark?,
//              substitutes?: [{ child_item_idx, qty, calc_code?, ext_n01?, remark? }] }] — 배열 순서가 곧 seq ( 대체 자재는 자재로 맞춘다 )
class BomService {

    public const LINE_ATTRIBUTES = [
        'child_item_idx'   => '자재',
        'qty'              => '소요',
        'yield_qty'        => '생산 가능 수량',
        'routing_line_idx' => '투입 지점',
        'calc_code'        => '계산식',
        'ext_n01'          => '기능형 값',
        'substitutes.*.child_item_idx' => '대체 자재',
        'substitutes.*.qty'            => '대체 자재 소요',
        'substitutes.*.yield_qty'      => '대체 자재 생산 가능 수량',
        'substitutes.*.calc_code'      => '대체 자재 계산식',
        'substitutes.*.ext_n01'        => '대체 자재 기능형 값',
    ];

    // 줄 검증 규칙 ( $prefix = 요청의 배열 이름 )
    public static function lineRules(string $prefix = 'lines'): array {
        return [
            "{$prefix}.*.bom_line_idx"     => 'nullable|integer',
            "{$prefix}.*.child_item_idx"   => 'required|integer|exists:item,item_idx',
            "{$prefix}.*.routing_line_idx" => 'nullable|integer|exists:routing_line,routing_line_idx',
            "{$prefix}.*.qty"              => 'required|numeric|gt:0|max:9999999999',
            // 생산 가능 수량 ( 자재 1개로 제품 N개 — 11-12 ) : 있으면 소요 = 올림( 수량 ÷ N ) · qty 는 서버가 1 ÷ N 으로 맞춘다
            "{$prefix}.*.yield_qty"        => 'nullable|numeric|gt:0|max:9999',
            "{$prefix}.*.calc_code"        => 'nullable|string|max:30',
            "{$prefix}.*.ext_n01"          => 'nullable|numeric|min:0|max:9999999999',
            "{$prefix}.*.remark"           => 'nullable|string|max:2000',
            "{$prefix}.*.substitutes"                           => 'nullable|array|max:20',
            "{$prefix}.*.substitutes.*.child_item_idx"          => 'required|integer|exists:item,item_idx',
            "{$prefix}.*.substitutes.*.qty"                     => 'required|numeric|gt:0|max:9999999999',
            "{$prefix}.*.substitutes.*.yield_qty"               => 'nullable|numeric|gt:0|max:9999',
            "{$prefix}.*.substitutes.*.calc_code"               => 'nullable|string|max:30',
            "{$prefix}.*.substitutes.*.ext_n01"                 => 'nullable|numeric|min:0|max:9999999999',
            "{$prefix}.*.substitutes.*.remark"                  => 'nullable|string|max:2000',
        ];
    }

    public static function lineAttributes(string $prefix = 'lines'): array {
        return collect(self::LINE_ATTRIBUTES)->mapWithKeys(fn ($v, $k) => ["{$prefix}.*.{$k}" => $v])->all();
    }

    public static function lineMessages(string $prefix = 'lines'): array {
        return [
            "{$prefix}.*.qty.gt"               => '소요는 0보다 커야 합니다.',
            "{$prefix}.*.substitutes.*.qty.gt" => '대체 자재 소요는 0보다 커야 합니다.',
        ];
    }

    /**
     * 업무 규칙 ( 실패는 ValidationException )
     *   자재 · 대체 자재 : 자기 자신 금지 · 순환 금지 / 대체 자재 : 그 줄의 주 자재와 같으면 안 됨 · 한 줄에 같은 대체 자재 두 번 금지
     *   같은 자재가 두 줄이어도 허용한다 ( 투입 지점이 다르면 정상 )
     */
    public function assertLines(int $itemIdx, array $lines, string $prefix = 'lines'): void {
        $edges  = $this->edges();
        $errors = [];

        $check = function (int $childIdx, string $key) use ($itemIdx, $edges, &$errors) {
            if ($childIdx === $itemIdx) {
                $errors[$key] = ['자기 자신을 자재로 넣을 수 없습니다.'];   // E_BOM_SELF
            } elseif ($this->reaches($edges, $childIdx, $itemIdx)) {
                $errors[$key] = ['순환 참조입니다. 이 자재의 BOM 이 이 품목을 포함합니다.'];   // E_BOM_CYCLE
            }
        };

        foreach (array_values($lines) as $i => $line) {
            $check((int) $line['child_item_idx'], "{$prefix}.{$i}.child_item_idx");

            $seen = [];
            foreach (array_values($line['substitutes'] ?? []) as $j => $sub) {
                $key = "{$prefix}.{$i}.substitutes.{$j}.child_item_idx";
                $sid = (int) $sub['child_item_idx'];
                if ($sid === (int) $line['child_item_idx']) {
                    $errors[$key] = ['주 자재와 같은 자재는 대체 자재로 넣을 수 없습니다.'];   // E_BOM_SUB_SAME
                    continue;
                }
                if (isset($seen[$sid])) {
                    $errors[$key] = ['같은 대체 자재가 두 번 들어 있습니다.'];   // E_BOM_SUB_DUP
                    continue;
                }
                $seen[$sid] = true;
                $check($sid, $key);
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    // 품목 → 하위 자재( 대체 자재 포함 ) — 순환 검사용
    public function edges(): array {
        // 품목 BOM 만 ( 수주 줄 자재는 그 수주 하나의 구성 — 다른 품목의 순환 판단에 넣지 않는다 )
        $lines = DB::table('bom')->where('bom.owner_type', Bom::OWNER_ITEM)->join('bom_line', 'bom.bom_idx', '=', 'bom_line.bom_idx')
            ->select('bom.item_idx', 'bom_line.child_item_idx');
        $subs = DB::table('bom')->where('bom.owner_type', Bom::OWNER_ITEM)->join('bom_line', 'bom.bom_idx', '=', 'bom_line.bom_idx')
            ->join('bom_line_substitute', 'bom_line_substitute.bom_line_idx', '=', 'bom_line.bom_line_idx')
            ->select('bom.item_idx', 'bom_line_substitute.child_item_idx');

        return $lines->union($subs)->get()
            ->groupBy('item_idx')
            ->map(fn ($rows) => $rows->pluck('child_item_idx')->map(fn ($v) => (int) $v)->all())
            ->all();
    }

    // $fromIdx 의 BOM 을 따라 내려가 $targetIdx 가 나오는가
    public function reaches(array $edges, int $fromIdx, int $targetIdx): bool {
        $seen  = [];
        $queue = [$fromIdx];
        while ($queue) {
            $current = array_pop($queue);
            if ($current === $targetIdx) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($edges[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }
        return false;
    }

    // 승인 해제 판단용 : 줄 · 대체 자재 구성이 바뀌었는가
    public function linesChanged(Bom $bom, array $lines): bool {
        // 생산 가능 수량으로 넣은 줄은 그 값으로 견준다 ( qty 는 1 ÷ N 을 반올림한 값이라 )
        $q   = fn ($qty, $yield) => (float) ($yield ?? 0) > 0 ? 'Y' . (float) $yield : (float) $qty;
        $sig = fn ($child, $rl, $qty, $calc, $ext, $subs, $yield = null) => json_encode([(int) $child, $rl ? (int) $rl : null, $q($qty, $yield), $calc ?: null, (float) ($ext ?? 0),
            collect($subs)->map(fn ($s) => [(int) $s['child_item_idx'], $q($s['qty'], $s['yield_qty'] ?? null), ($s['calc_code'] ?? null) ?: null, (float) ($s['ext_n01'] ?? 0)])->values()->all()]);

        $current = $bom->lines()->with('substitutes')->get()
            ->map(fn (BomLine $l) => $sig($l->child_item_idx, $l->routing_line_idx, $l->qty, $l->calc_code, $l->ext_n01, $l->substitutes->toArray(), $l->yield_qty))
            ->sort()->values()->all();
        $next = collect($lines)
            ->map(fn ($l) => $sig($l['child_item_idx'], $l['routing_line_idx'] ?? null, $l['qty'], $l['calc_code'] ?? null, $l['ext_n01'] ?? null, $l['substitutes'] ?? [], $l['yield_qty'] ?? null))
            ->sort()->values()->all();

        return $current !== $next;
    }

    // 줄 동기화 ( 요청에 없는 기존 줄은 삭제 — 전표가 참조하지 않는다 · 대체 자재는 줄과 같이 cascade )
    //   새 줄은 source_bom_line_idx( 복사해 온 원본 줄 )를 받는다 ( 수주 자재 )
    public function syncLines(Bom $bom, array $lines): void {
        $existing = BomLine::where('bom_idx', $bom->bom_idx)->get()->keyBy('bom_line_idx');
        $keep     = [];

        foreach (array_values($lines) as $order => $row) {
            $line = (!empty($row['bom_line_idx']) && $existing->has($row['bom_line_idx']))
                ? $existing->get($row['bom_line_idx'])
                : new BomLine(['bom_idx' => $bom->bom_idx, 'source_bom_line_idx' => ($row['source_bom_line_idx'] ?? null) ?: null]);

            $line->fill([
                'seq'              => $order + 1,
                'child_item_idx'   => $row['child_item_idx'],
                'routing_line_idx' => $row['routing_line_idx'] ?? null,
                ...self::qtyColumns($row),
                'calc_code'        => $row['calc_code'] ?? null,
                'ext_n01'          => isset($row['ext_n01']) && $row['ext_n01'] !== '' ? (string) $row['ext_n01'] : null,
                'remark'           => $row['remark'] ?? null,
            ])->save();

            $this->syncSubstitutes($line, $row['substitutes'] ?? []);
            $keep[] = $line->bom_line_idx;
        }

        BomLine::where('bom_idx', $bom->bom_idx)->whereNotIn('bom_line_idx', $keep ?: [0])->delete();
    }

    // 대체 자재 동기화 ( 배열 순서가 곧 seq )
    //   한 줄에 같은 대체 자재는 하나 → 자재로 맞춘다 ( 지웠다 다시 넣거나 두 줄의 자재를 맞바꿔도 유일 키에 걸리지 않게 )
    public function syncSubstitutes(BomLine $line, array $subs): void {
        $byChild = BomLineSubstitute::where('bom_line_idx', $line->bom_line_idx)->get()->keyBy('child_item_idx');
        $want    = collect(array_values($subs))->keyBy(fn ($r) => (int) $r['child_item_idx']);

        BomLineSubstitute::where('bom_line_idx', $line->bom_line_idx)->whereNotIn('child_item_idx', $want->keys()->all() ?: [0])->delete();

        foreach ($want->values() as $order => $row) {
            $sub = $byChild->get((int) $row['child_item_idx']) ?? new BomLineSubstitute(['bom_line_idx' => $line->bom_line_idx]);
            $sub->fill([
                'seq'            => $order + 1,
                'child_item_idx' => $row['child_item_idx'],
                ...self::qtyColumns($row),
                'calc_code'      => $row['calc_code'] ?? null,
                'ext_n01'        => isset($row['ext_n01']) && $row['ext_n01'] !== '' ? (string) $row['ext_n01'] : null,
                'remark'         => $row['remark'] ?? null,
            ])->save();
        }
    }

    // 소요 칸 [ qty, yield_qty ] — 생산 가능 수량( 자재 1개로 제품 N개 · 11-12 )이 있으면 qty = 1 ÷ N ( 표시 · 호환용 — 계산은 MaterialNeed 가 yield 로 )
    public static function qtyColumns(array $row): array {
        $yield = isset($row['yield_qty']) && $row['yield_qty'] !== '' && (float) $row['yield_qty'] > 0 ? (float) $row['yield_qty'] : null;
        return [
            'qty'       => $yield ? \MesCore\Support\MaterialNeed::qtyOfYield($yield) : (string) $row['qty'],
            'yield_qty' => $yield ? (string) $yield : null,
        ];
    }

    // 다른 BOM 으로 줄 · 대체 자재 복사 ( 버전 추가 · 수주 자재 ) → [ 원본 줄 IDX => 새 줄 ]
    //   $routingLineOf : 투입 지점 바꾸기 ( 원본 줄의 routing_line_idx → 새 값 ) — 없으면 그대로
    public function copyLines(Bom $from, Bom $to, ?callable $routingLineOf = null, bool $keepSource = false): array {
        $map = [];
        foreach ($from->lines()->with('substitutes')->get() as $line) {
            $copy = BomLine::create(array_merge([
                'bom_idx'          => $to->bom_idx,
                'seq'              => $line->seq,
                'child_item_idx'   => $line->child_item_idx,
                'routing_line_idx' => $routingLineOf ? $routingLineOf($line->routing_line_idx) : $line->routing_line_idx,
                'qty'              => (string) $line->qty,
                'yield_qty'        => $line->yield_qty !== null ? (string) $line->yield_qty : null,
                'calc_code'        => $line->calc_code,
                'ext_n01'          => $line->ext_n01 !== null ? (string) $line->ext_n01 : null,
                'remark'           => $line->remark,
            ], $keepSource ? ['source_bom_line_idx' => $line->bom_line_idx] : []));

            foreach ($line->substitutes as $sub) {
                BomLineSubstitute::create([
                    'bom_line_idx'   => $copy->bom_line_idx,
                    'seq'            => $sub->seq,
                    'child_item_idx' => $sub->child_item_idx,
                    'qty'            => (string) $sub->qty,
                    'yield_qty'      => $sub->yield_qty !== null ? (string) $sub->yield_qty : null,
                    'calc_code'      => $sub->calc_code,
                    'ext_n01'        => $sub->ext_n01 !== null ? (string) $sub->ext_n01 : null,
                    'remark'         => $sub->remark,
                ]);
            }
            $map[$line->bom_line_idx] = $copy;
        }
        return $map;
    }

    // ---- 수주 줄 자재 ( ② ) ----

    // 품목의 활성 품목 BOM ( 수주 자재 · 작업지시 자재의 원본 )
    public function activeItemBom(?int $itemIdx): ?Bom {
        return $itemIdx ? Bom::itemBoms()->where('item_idx', $itemIdx)->where('is_active', 'Y')->first() : null;
    }

    /**
     * BOM 트리 데이터 ( BOM관리 [트리 보기] · 11-11 회의 → 11-12 트리에서 편집 )
     *   맨 위 품목에서 활성 품목 BOM 을 따라 내려가며 닿는 품목 · BOM 을 품목별로 모은다 ( 화면이 트리로 그리고 소요를 곱한다 )
     *   items : [ item_idx => 품목 ] / boms : [ item_idx => { bom_idx, version, approved, lines[ { bom_line_idx, child_item_idx, qty, substitutes[ { child_item_idx, qty } ] } ] } ]
     *   대체 자재의 BOM 도 따라간다 · 같은 품목은 한 번만 ( 순환이 있어도 끝난다 ) · 품목 상한 500
     */
    public function treeData(int $rootIdx): array {
        $items = [];
        $boms  = [];
        $queue = [$rootIdx];
        $seen  = [$rootIdx => true];
        while ($queue && count($seen) <= 500) {
            $batch = $queue;
            $queue = [];
            $active = Bom::itemBoms()->whereIn('item_idx', $batch)->where('is_active', 'Y')
                ->with(['lines.substitutes'])->get()->keyBy('item_idx');
            foreach ($batch as $idx) {
                $bom = $active->get($idx);
                if (!$bom) continue;
                $boms[$idx] = [
                    'bom_idx'  => (int) $bom->bom_idx,
                    'version'  => $bom->version,
                    'approved' => $bom->isApproved(),
                    'lines'    => $bom->lines->map(fn (BomLine $l) => [
                        'bom_line_idx'   => (int) $l->bom_line_idx,
                        'child_item_idx' => (int) $l->child_item_idx,
                        'qty'            => (float) $l->qty,
                        'yield_qty'      => $l->yield_qty !== null ? (float) $l->yield_qty : null,
                        'substitutes'    => $l->substitutes->map(fn ($x) => ['child_item_idx' => (int) $x->child_item_idx, 'qty' => (float) $x->qty, 'yield_qty' => $x->yield_qty !== null ? (float) $x->yield_qty : null])->values()->all(),
                    ])->values()->all(),
                ];
                foreach ($boms[$idx]['lines'] as $l) {
                    foreach ([$l['child_item_idx'], ...array_column($l['substitutes'], 'child_item_idx')] as $child) {
                        if (!isset($seen[$child])) {
                            $seen[$child] = true;
                            $queue[] = $child;
                        }
                    }
                }
            }
        }
        foreach (DB::table('item')->whereIn('item_idx', array_keys($seen))
            ->get(['item_idx', 'item_code', 'item_name', 'spec', 'material', 'spec_unit', 'item_type', 'item_kind']) as $it) {
            $items[(int) $it->item_idx] = (array) $it;
        }
        return ['items' => $items, 'boms' => $boms];
    }

    /**
     * BOM 트리에서 저장 ( 11-12 ) — 고친 품목들의 활성 BOM 을 한 번에 ( 부르는 쪽이 트랜잭션 )
     *   $boms : [ { item_idx, lines[ { bom_line_idx?, child_item_idx, qty, substitutes[ { child_item_idx, qty } ] } ] } ]
     *   트리는 자재 · 소요 · 대체 자재만 다룬다 → 기존 줄의 투입 지점 · 계산식 · 비고는 그대로 둔다 ( bom_line_idx 로 잇는다 )
     *   활성 BOM 이 없으면 버전 1 을 만들어 활성으로 ( 비활성 버전만 있으면 막는다 — BOM관리에서 활성 전환 )
     *   승인된 BOM 의 구성이 바뀌면 승인이 풀린다 ( BOM관리 저장과 같은 규칙 )
     *   @return array{created: list<string>, unapproved: list<string>} 품번
     */
    public function saveTree(array $boms): array {
        $created = $unapproved = [];
        foreach (array_values($boms) as $i => $b) {
            $itemIdx = (int) $b['item_idx'];
            $item    = DB::table('item')->where('item_idx', $itemIdx)->first(['item_code', 'item_type']);
            if (!$item || $item->item_type !== 'PRODUCT') {
                throw ValidationException::withMessages(["boms.{$i}.item_idx" => [($item->item_code ?? '품목') . ' — 자재에는 BOM 을 만들 수 없습니다.']]);
            }
            $bom      = $this->activeItemBom($itemIdx);
            $existing = $bom ? $bom->lines()->with('substitutes')->get()->keyBy('bom_line_idx') : collect();

            $rows = [];
            foreach (array_values($b['lines'] ?? []) as $row) {
                $old     = !empty($row['bom_line_idx']) ? $existing->get($row['bom_line_idx']) : null;
                $oldSubs = $old ? $old->substitutes->keyBy('child_item_idx') : collect();
                $rows[] = [
                    'bom_line_idx'     => $old?->bom_line_idx,
                    'child_item_idx'   => (int) $row['child_item_idx'],
                    'qty'              => $row['qty'],
                    'yield_qty'        => $row['yield_qty'] ?? null,
                    'routing_line_idx' => $old?->routing_line_idx,
                    'calc_code'        => $old?->calc_code,
                    'ext_n01'          => $old?->ext_n01,
                    'remark'           => $old?->remark,
                    'substitutes'      => array_map(function ($x) use ($oldSubs) {
                        $o = $oldSubs->get((int) $x['child_item_idx']);
                        return ['child_item_idx' => (int) $x['child_item_idx'], 'qty' => $x['qty'], 'yield_qty' => $x['yield_qty'] ?? null, 'calc_code' => $o?->calc_code, 'ext_n01' => $o?->ext_n01, 'remark' => $o?->remark];
                    }, array_values($row['substitutes'] ?? [])),
                ];
            }

            if (!$bom) {
                if (!$rows) continue;
                if (Bom::itemBoms()->where('item_idx', $itemIdx)->exists()) {
                    throw ValidationException::withMessages(["boms.{$i}.item_idx" => ["{$item->item_code} — 활성 버전이 없습니다. BOM관리에서 버전을 활성으로 전환한 뒤 고치세요."]]);
                }
                $bom = Bom::create(['item_idx' => $itemIdx, 'version' => '1', 'is_active' => 'Y', 'activated_at' => now()]);
                $created[] = $item->item_code;
            }

            // 자기 자신 · 순환 · 대체 자재 규칙 — 앞 품목의 저장 결과까지 보고 검사한다
            $this->assertLines($itemIdx, $rows, "boms.{$i}.lines");

            if ($bom->isApproved() && $this->linesChanged($bom, $rows)) {
                $bom->clearApproval();
                $bom->save();
                $unapproved[] = $item->item_code;
            }
            $this->syncLines($bom, $rows);
        }
        return ['created' => $created, 'unapproved' => $unapproved];
    }

    public function orderLineBom(int $lineIdx): ?Bom {
        return Bom::where('owner_type', Bom::OWNER_ORDER_LINE)->where('sales_order_line_idx', $lineIdx)->first();
    }

    /**
     * 수주 줄 저장 뒤 수주 자재 맞추기 ( 줄마다 항상 — 작업지시 자재의 고정 원본 )
     *   $lines : 화면이 보낸 자재 / null = 손대지 않음 ( 없거나 품목을 바꿨으면 활성 품목 BOM 을 복사 )
     *   투입 지점 : 화면은 수주 공정의 순서( routing_seq )를 보낸다 → 저장된 수주 공정 줄로 바꾼다
     *             복사할 때는 품목 BOM 의 투입 지점( 품목 공정 줄 ) → 그 줄을 원본으로 둔 수주 공정 줄 ( 없으면 첫 수량 공정 )
     *   잠금( 작업지시 생성 )은 부르는 쪽이 먼저 막는다
     */
    public function syncOrderLineBom(SalesOrderLine $line, string $orderNo, ?array $lines): void {
        $own         = $this->orderLineBom($line->sales_order_line_idx);
        $base        = $this->activeItemBom((int) $line->item_idx);
        $baseIdx     = $base?->bom_idx;
        $baseChanged = $own && ((int) $own->base_bom_idx !== (int) $baseIdx || (int) $own->item_idx !== (int) $line->item_idx);

        $routingLines = $this->orderRoutingLines($line->sales_order_line_idx);
        if ($lines === null) {
            if ($own && !$baseChanged) {
                return;
            }
            $lines = $base ? $this->copyRows($base, $routingLines) : [];
        } else {
            // 화면 : 수주 공정의 순서 → 줄 IDX
            $bySeq = $routingLines->values();
            $lines = array_map(function ($r) use ($bySeq) {
                $seq = (int) ($r['routing_seq'] ?? 0);
                $r['routing_line_idx'] = $seq > 0 ? ($bySeq[$seq - 1]->routing_line_idx ?? null) : null;
                return $r;
            }, array_values($lines));
        }

        $isNew   = !$own;
        $oldBase = $own?->base_bom_idx;
        $own ??= new Bom([
            'owner_type'           => Bom::OWNER_ORDER_LINE,
            'sales_order_line_idx' => $line->sales_order_line_idx,
            'is_active'            => 'N',
        ]);
        $own->fill([
            'item_idx'     => $line->item_idx,
            'version'      => 'SO-' . $line->sales_order_line_idx,
            'base_bom_idx' => $baseIdx,
            'remark'       => mb_substr("{$orderNo} #{$line->line_no} 수주 자재", 0, 200),
        ])->save();

        // 이력 비교 기준 : 처음 · 다시 불러옴 = 원본 품목 BOM( 원본 줄 IDX 로 맞춘다 ) / 수정 = 지금 수주 자재
        $before = ($isNew || $baseChanged)
            ? ($base ? $this->snapshotLines($base, false) : [])
            : $this->snapshotLines($own, false);
        if ($isNew || $baseChanged) {
            BomChangeLog::create([
                'scope'       => BomChangeLog::SCOPE_ORDER_LINE,
                'ref_idx'     => $line->sales_order_line_idx,
                'bom_idx'     => $own->bom_idx,
                'action'      => $isNew ? 'CREATE' : 'RELOAD',
                'before_json' => $isNew ? null : ['base_bom_idx' => $oldBase, 'lines' => $this->summary($own)],
                'after_json'  => ['base_bom_idx' => $baseIdx, 'lines' => collect($lines)->map(fn ($r) => ['child_item_idx' => (int) $r['child_item_idx'], 'qty' => (float) $r['qty']])->values()->all()],
            ]);
        }

        $this->syncLines($own, $lines);
        $this->logDiff($line->sales_order_line_idx, $own->bom_idx, $before, $this->snapshotLines($own->fresh(), $isNew || $baseChanged), $isNew || $baseChanged);
    }

    /**
     * 수주에서 고른 자재를 품목 BOM( ① 활성 )에도 등록 — 수주 사본 맞추기( syncOrderLineBom ) 전에 ( docs/대체자재-수주자재-설계.md §8 )
     *   품목 BOM 없음        → BOM 을 새로 만들고( 활성 ) 고른 자재 줄을 그대로 ( 첫 줄 = 주 자재 )
     *   품목 BOM 에 이미 있음 → 그대로 ( 소요 등 덮어쓰지 않음 · 수주에서 지운 자재도 품목 BOM 에서는 안 지움 )
     *   새 자재
     *     원본 줄( source_bom_line_idx )의 자재를 바꾼 것 → 그 줄의 대체 자재
     *     새로 더한 줄 → item_bom_target : 품목 BOM 줄 IDX( 그 줄의 대체 ) / 'NEW_LINE'( 새 자재 줄 ) / 없으면 첫 줄의 대체
     *   대체 자재 : 그 주 자재가 들어간( 또는 들어갈 ) 품목 BOM 줄의 대체
     *   승인 : 대체 자재만 더하면 그대로 · 자재 줄이 늘면( 구성 변경 ) 승인을 푼다
     *   → 화면 줄( 새 자재 줄로 들어간 것은 source_bom_line_idx 를 채워 "수정됨" 비교가 맞게 )
     */
    public function absorbIntoItemBom(SalesOrderLine $line, string $orderNo, array $rows): array {
        $itemIdx = (int) $line->item_idx;
        $base    = $this->activeItemBom($itemIdx);
        $log     = fn (Bom $bom, array $row) => BomChangeLog::create($row + [
            'scope' => BomChangeLog::SCOPE_ITEM, 'ref_idx' => $itemIdx, 'bom_idx' => $bom->bom_idx,
        ]);
        $from = ['from' => $orderNo];

        // 수주 공정 줄 → 품목 공정 줄 ( 새 자재 줄의 투입 지점 — 품목 라우팅의 줄일 때만 )
        $orderRouting = $this->orderRoutingLines($line->sales_order_line_idx)->values();
        $itemRouting  = Routing::where('owner_type', Routing::OWNER_ITEM)->where('item_idx', $itemIdx)->pluck('routing_idx')->all();
        $itemRoutingLine = function (array $r) use ($orderRouting, $itemRouting) {
            $seq = (int) ($r['routing_seq'] ?? 0);
            $src = $seq > 0 ? ($orderRouting[$seq - 1]->source_routing_line_idx ?? null) : null;
            return $src && RoutingLine::whereKey($src)->whereIn('routing_idx', $itemRouting ?: [0])->exists() ? (int) $src : null;
        };
        $lineRow = fn (array $r, int $seq, int $bomIdx) => [
            'bom_idx' => $bomIdx, 'seq' => $seq, 'child_item_idx' => $r['child_item_idx'], 'routing_line_idx' => $itemRoutingLine($r),
            ...self::qtyColumns($r), 'calc_code' => $r['calc_code'] ?? null,
            'ext_n01' => isset($r['ext_n01']) && $r['ext_n01'] !== '' ? (string) $r['ext_n01'] : null, 'remark' => $r['remark'] ?? null,
        ];
        $subRow = fn (array $s) => [
            ...self::qtyColumns($s), 'calc_code' => $s['calc_code'] ?? null,
            'ext_n01' => isset($s['ext_n01']) && $s['ext_n01'] !== '' ? (string) $s['ext_n01'] : null, 'remark' => $s['remark'] ?? null,
        ];

        // ---- 품목 BOM 없음 : 새로 만든다 ----
        if (!$base) {
            if (!$rows) {
                return $rows;
            }
            $n = Bom::itemBoms()->where('item_idx', $itemIdx)->count() + 1;
            while (Bom::where('item_idx', $itemIdx)->where('version', "V{$n}")->exists()) {
                $n++;
            }
            $base = Bom::create([
                'item_idx' => $itemIdx, 'version' => "V{$n}", 'is_active' => 'Y', 'activated_at' => now(),
                'remark' => mb_substr("수주 {$orderNo} 에서 자동 생성", 0, 200),
            ]);
            $log($base, ['action' => 'CREATE', 'after_json' => $from + ['lines' => collect($rows)->map(fn ($r) => ['child_item_idx' => (int) $r['child_item_idx'], 'qty' => (float) $r['qty']])->values()->all()]]);
            foreach ($rows as $i => $r) {
                $bl = BomLine::create($lineRow($r, $i + 1, $base->bom_idx));
                foreach (array_values($r['substitutes'] ?? []) as $k => $s) {
                    BomLineSubstitute::create(['bom_line_idx' => $bl->bom_line_idx, 'seq' => $k + 1, 'child_item_idx' => $s['child_item_idx']] + $subRow($s));
                }
                $rows[$i]['source_bom_line_idx'] = $bl->bom_line_idx;
            }
            return $rows;
        }

        // ---- 품목 BOM 있음 : 새 자재만 더한다 ----
        $lines   = $base->lines()->with('substitutes')->orderBy('seq')->get();
        $byIdx   = $lines->keyBy('bom_line_idx');
        $lineOf  = [];   // 자재 IDX → 그 자재가 있는 품목 BOM 줄 ( 주 자재 · 대체 자재 )
        foreach ($lines as $l) {
            $lineOf[(int) $l->child_item_idx] ??= $l;
            foreach ($l->substitutes as $s) {
                $lineOf[(int) $s->child_item_idx] ??= $l;
            }
        }
        $grew = false;

        $addSub = function (BomLine $target, array $s) use (&$lineOf, $log, $base, $from, $subRow) {
            $cid = (int) $s['child_item_idx'];
            if (isset($lineOf[$cid])) {
                return;
            }
            BomLineSubstitute::create([
                'bom_line_idx' => $target->bom_line_idx, 'seq' => (int) $target->substitutes()->max('seq') + 1, 'child_item_idx' => $cid,
            ] + $subRow($s));
            $lineOf[$cid] = $target;
            $log($base, ['action' => 'SUB_ADD', 'child_item_idx' => $cid, 'main_item_idx' => (int) $target->child_item_idx, 'after_json' => $from + ['qty' => (float) $s['qty']]]);
        };

        foreach ($rows as $i => $r) {
            $cid = (int) $r['child_item_idx'];
            if (isset($lineOf[$cid])) {
                $target = $lineOf[$cid];
            } else {
                $src    = !empty($r['source_bom_line_idx']) ? $byIdx->get((int) $r['source_bom_line_idx']) : null;
                $want   = $r['item_bom_target'] ?? null;
                if ($src) {
                    $target = $src;
                } elseif ($want === 'NEW_LINE') {
                    $target = BomLine::create($lineRow($r, (int) $base->lines()->max('seq') + 1, $base->bom_idx));
                    $lineOf[$cid] = $target;
                    $rows[$i]['source_bom_line_idx'] = $target->bom_line_idx;
                    $grew = true;
                    $log($base, ['action' => 'ADD', 'child_item_idx' => $cid, 'after_json' => $from + ['qty' => (float) $r['qty']]]);
                } else {
                    $target = ($want && $byIdx->has((int) $want)) ? $byIdx->get((int) $want) : $lines->first();
                }
                if (!isset($lineOf[$cid])) {
                    $addSub($target, $r);
                }
            }
            foreach (array_values($r['substitutes'] ?? []) as $s) {
                $addSub($target, $s);
            }
        }

        // 구성 변경( 자재 줄 추가 ) → 승인 해제 · 대체 자재만이면 그대로
        if ($grew && $base->isApproved()) {
            $base->clearApproval();
            $base->save();
        }
        return $rows;
    }

    // 수주 줄들의 자재 구성 열쇠 ( 같은 지시에 담을 수 있는지 — 자재 · 소요 · 계산식 · 투입 공정 순서 · 대체 자재가 같아야 한다 ) [ 줄 IDX => 열쇠 ]
    //   투입 공정은 수주마다 다른 수주 공정 줄이므로 그 줄의 순서( seq )로 본다
    public function orderLineMaterialKeys(array $lineIdxs): array {
        if (!$lineIdxs) {
            return [];
        }
        return Bom::where('owner_type', Bom::OWNER_ORDER_LINE)->whereIn('sales_order_line_idx', $lineIdxs)
            ->with(['lines.substitutes', 'lines.routingLine:routing_line_idx,seq'])->get()
            ->mapWithKeys(fn (Bom $b) => [$b->sales_order_line_idx => md5(json_encode($b->lines->map(fn (BomLine $l) => [
                (int) $l->child_item_idx, (float) $l->qty, $l->calc_code ?: null, $l->ext_n01 !== null ? (float) $l->ext_n01 : null, $l->routingLine?->seq,
                $l->substitutes->map(fn ($s) => [(int) $s->child_item_idx, (float) $s->qty, $s->calc_code ?: null, $s->ext_n01 !== null ? (float) $s->ext_n01 : null, $s->yield_qty !== null ? (float) $s->yield_qty : null])->values()->all(),
                $l->yield_qty !== null ? (float) $l->yield_qty : null,
            ])->values()->all()))])
            ->all();
    }

    // BOM 에 들어 있는 자재 · 대체 자재 품목 IDX ( 수주 자재는 자재만 — 이미 들어 있던 제품 하위는 그대로 허용할 때 )
    public function childIdsOf(?Bom $bom): array {
        if (!$bom) {
            return [];
        }
        $lines = $bom->lines()->with('substitutes')->get();
        return $lines->pluck('child_item_idx')->merge($lines->flatMap(fn ($l) => $l->substitutes->pluck('child_item_idx')))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    // 수주 자재가 원본( 품목 BOM )과 다른가 — 화면의 "수정됨"
    public function differsFromBase(Bom $own): bool {
        $base = $own->base_bom_idx ? Bom::find($own->base_bom_idx) : null;
        $sig  = fn (array $rows) => json_encode(array_map(fn ($r) => [$r['child_item_idx'], $r['values'], $r['subs']], $rows));
        $mine = $this->snapshotLines($own, false);
        $orig = $base ? $this->snapshotLines($base, false) : [];
        // 투입 지점은 수주 공정 줄로 바뀌므로 비교에서 뺀다 ( 공정은 공정 쪽 "수정됨" 으로 )
        $strip = fn ($rows) => array_map(fn ($r) => array_merge($r, ['values' => array_diff_key($r['values'], ['routing_line_idx' => 1])]), $rows);
        return $sig($strip($mine)) !== $sig($strip($orig));
    }

    // 화면 · 응답용 줄 ( 대체 자재 포함 · 투입 지점 = 수주 공정 줄 )
    public function linesPayload(Bom $bom): array {
        return $bom->lines()->with(['childItem', 'substitutes.childItem'])->get()->map(fn (BomLine $l) => [
            'bom_line_idx'        => $l->bom_line_idx,
            'source_bom_line_idx' => $l->source_bom_line_idx,
            'child_item_idx'      => $l->child_item_idx,
            'child_item_code'     => $l->childItem?->item_code,
            'child_item_name'     => $l->childItem?->item_name,
            'child_item_spec'     => $l->childItem?->spec,
            'child_item_unit'     => $l->childItem?->spec_unit,
            'child_item_kind'     => $l->childItem?->item_kind,
            'routing_line_idx'    => $l->routing_line_idx,
            'qty'                 => $l->qty,
            'yield_qty'           => $l->yield_qty,
            'calc_code'           => $l->calc_code,
            'ext_n01'             => $l->ext_n01,
            'remark'              => $l->remark,
            'substitutes'         => $this->substitutesPayload($l),
        ])->values()->all();
    }

    /**
     * 원본 BOM 의 줄을 "복사할 새 줄"로 ( 수주 화면 · 서버 복사 )
     *   routing_source_line_idx : 원본 줄의 투입 지점( 품목 공정 줄 ) — 화면이 수주 공정 줄( 그 줄을 원본으로 둔 줄 )에 잇는다
     *   $routingLines 를 주면 서버가 바로 routing_line_idx 를 채운다
     */
    public function copyRows(Bom $from, $routingLines = null): array {
        $bySource = $routingLines ? $routingLines->filter(fn ($l) => $l->source_routing_line_idx)->keyBy('source_routing_line_idx') : collect();
        return collect($this->linesPayload($from))->map(fn ($l) => array_merge($l, [
            'bom_line_idx'            => null,
            'source_bom_line_idx'     => $l['bom_line_idx'],
            'routing_source_line_idx' => $l['routing_line_idx'],
            'routing_line_idx'        => $l['routing_line_idx'] ? ($bySource[$l['routing_line_idx']]?->routing_line_idx ?? null) : null,
        ]))->all();
    }

    // 수주 줄의 수주 공정 줄 ( 순서대로 )
    private function orderRoutingLines(int $lineIdx) {
        $routingIdx = Routing::where('owner_type', Routing::OWNER_ORDER_LINE)->where('sales_order_line_idx', $lineIdx)->value('routing_idx');
        return $routingIdx ? RoutingLine::where('routing_idx', $routingIdx)->orderBy('seq')->get() : collect();
    }

    private function summary(Bom $bom): array {
        return $bom->lines()->get()->map(fn ($l) => ['child_item_idx' => (int) $l->child_item_idx, 'qty' => (float) $l->qty])->values()->all();
    }

    // 비교용 줄 ( key : 같은 줄 — 처음 · 다시 불러옴이면 원본 줄 IDX )
    private function snapshotLines(Bom $bom, bool $keyBySource): array {
        return $bom->lines()->with('substitutes')->get()->map(fn (BomLine $l) => [
            'key'            => $keyBySource ? $l->source_bom_line_idx : $l->bom_line_idx,
            'child_item_idx' => (int) $l->child_item_idx,
            'values'         => [
                'qty'              => (float) $l->qty,
                'yield_qty'        => $l->yield_qty !== null ? (float) $l->yield_qty : null,
                'routing_line_idx' => $l->routing_line_idx ? (int) $l->routing_line_idx : null,
                'calc_code'        => $l->calc_code ?: null,
                'ext_n01'          => $l->ext_n01 !== null ? (float) $l->ext_n01 : null,
            ],
            'subs'           => $l->substitutes->mapWithKeys(fn ($s) => [(int) $s->child_item_idx => [
                'qty' => (float) $s->qty, 'yield_qty' => $s->yield_qty !== null ? (float) $s->yield_qty : null, 'calc_code' => $s->calc_code ?: null, 'ext_n01' => $s->ext_n01 !== null ? (float) $s->ext_n01 : null,
            ]])->all(),
        ])->values()->all();
    }

    // 자재 줄 · 대체 자재 비교 → 이력 ( 처음 · 다시 불러옴이면 투입 지점은 공정 쪽이 다르므로 비교하지 않는다 )
    private function logDiff(int $lineIdx, int $bomIdx, array $before, array $after, bool $fromBase = false): void {
        if ($fromBase) {
            $strip  = fn ($rows) => array_map(fn ($r) => array_merge($r, ['values' => array_diff_key($r['values'], ['routing_line_idx' => 1])]), $rows);
            $before = $strip($before);
            $after  = $strip($after);
        }
        $byKey = collect($before)->filter(fn ($b) => $b['key'] !== null)->keyBy('key');
        $seen  = [];
        $log   = fn (array $row) => BomChangeLog::create($row + ['scope' => BomChangeLog::SCOPE_ORDER_LINE, 'ref_idx' => $lineIdx, 'bom_idx' => $bomIdx]);

        foreach ($after as $a) {
            $b = $a['key'] !== null ? $byKey->get($a['key']) : null;
            if (!$b || $b['child_item_idx'] !== $a['child_item_idx']) {
                if ($b) {
                    $log(['action' => 'REMOVE', 'child_item_idx' => $b['child_item_idx'], 'before_json' => $b['values']]);
                    $seen[$a['key']] = true;
                }
                $log(['action' => 'ADD', 'child_item_idx' => $a['child_item_idx'], 'after_json' => $a['values']]);
                foreach ($a['subs'] as $sid => $sv) {
                    $log(['action' => 'SUB_ADD', 'child_item_idx' => $sid, 'main_item_idx' => $a['child_item_idx'], 'after_json' => $sv]);
                }
                continue;
            }
            $seen[$a['key']] = true;

            $changed = array_keys(array_filter($a['values'], fn ($v, $k) => $v != $b['values'][$k], ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $log(['action' => 'UPDATE', 'child_item_idx' => $a['child_item_idx'],
                    'before_json' => array_intersect_key($b['values'], array_flip($changed)), 'after_json' => array_intersect_key($a['values'], array_flip($changed))]);
            }
            foreach ($a['subs'] as $sid => $sv) {
                if (!isset($b['subs'][$sid])) {
                    $log(['action' => 'SUB_ADD', 'child_item_idx' => $sid, 'main_item_idx' => $a['child_item_idx'], 'after_json' => $sv]);
                } elseif ($sv != $b['subs'][$sid]) {
                    $log(['action' => 'SUB_UPDATE', 'child_item_idx' => $sid, 'main_item_idx' => $a['child_item_idx'], 'before_json' => $b['subs'][$sid], 'after_json' => $sv]);
                }
            }
            foreach (array_diff_key($b['subs'], $a['subs']) as $sid => $sv) {
                $log(['action' => 'SUB_REMOVE', 'child_item_idx' => $sid, 'main_item_idx' => $a['child_item_idx'], 'before_json' => $sv]);
            }
        }

        foreach ($before as $b) {
            if ($b['key'] === null || !isset($seen[$b['key']])) {
                $log(['action' => 'REMOVE', 'child_item_idx' => $b['child_item_idx'], 'before_json' => $b['values']]);
            }
        }
    }

    // 화면용 대체 자재
    public function substitutesPayload(BomLine $line): array {
        return $line->substitutes->map(fn (BomLineSubstitute $s) => [
            'bom_line_substitute_idx' => $s->bom_line_substitute_idx,
            'child_item_idx'          => $s->child_item_idx,
            'child_item_code'         => $s->childItem?->item_code,
            'child_item_name'         => $s->childItem?->item_name,
            'child_item_spec'         => $s->childItem?->spec,
            'child_item_unit'         => $s->childItem?->spec_unit,
            'child_item_kind'         => $s->childItem?->item_kind,
            'qty'                     => $s->qty,
            'yield_qty'               => $s->yield_qty,
            'calc_code'               => $s->calc_code,
            'ext_n01'                 => $s->ext_n01,
            'remark'                  => $s->remark,
        ])->values()->all();
    }
}

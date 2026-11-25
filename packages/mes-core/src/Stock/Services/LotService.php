<?php

namespace MesCore\Stock\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\LotRule;
use MesCore\Stock\Models\Lot;

/**
 * LOT 발급 — 40-구매자재 §10.3 · 50-생산 §5 · 재고-수불-설계 §2-1
 *
 *   생산 LOT : 생산 기준( PRODUCE_START · PRODUCE_END ) 규칙으로 작업지시 저장 때 — issueProductionLot()
 *
 *   자재( 입고 ) LOT
 *     lot.material_source = INTERNAL ( 기본 ) : 자재( MATERIAL ) 기준 LOT 규칙으로 발급, 공급처 번호는 external_lot_no
 *                         = EXTERNAL          : 공급처 번호를 그대로 lot_no 로 ( 입력 필수 )
 *     feature.lot 이 꺼져 있으면 LOT 을 만들지 않는다 ( lot_idx = 0 )
 *   순번 : lot_seq( 규칙 × 리셋 구간 )를 잠그고 +1. 번호는 재사용하지 않는다
 */
class LotService {

    public function __construct(private PolicyService $policy) {}

    /**
     * 표시 LOT 번호 바꾸기 ( 11-24 · docs/LOT표시번호-설계.md ) — 비우거나 원번호와 같으면 원번호로 ( lot_title = NULL )
     *   다른 LOT 의 원번호 · 표시 번호와 겹치면 막는다 ( 스캔 · 검색이 한 LOT 으로만 )
     *   이미 발행한 라벨은 그대로 ( 바코드 = 발행 당시 번호 ) — 새 번호 바코드는 [새로 발행]
     *   번호가 실제로 바뀌면 lot_title_log 에 한 줄 ( 11-25 · MES_1125 #14 )
     */
    public function rename(int $lotIdx, ?string $title): Lot {
        return DB::transaction(function () use ($lotIdx, $title) {
            $lot = Lot::whereKey($lotIdx)->lockForUpdate()->first();
            if (!$lot) {
                throw ValidationException::withMessages(['lot_idx' => ['LOT 을 찾을 수 없습니다.']]);
            }
            $title = $title !== null ? trim($title) : '';
            $orig  = $lot->original_no;
            if ($title === '' || $title === $orig) {
                $title = null;
            } else {
                if (mb_strlen($title) > 60) {
                    throw ValidationException::withMessages(['lot_title' => ['LOT 번호는 60자까지입니다.']]);
                }
                $this->assertTitleFree($title, $lotIdx);
            }
            $before = $lot->lot_no;   // 바꾸기 전 표시 번호
            $lot->lot_title = $title;
            $lot->save();
            $lot = $lot->fresh();
            if ($lot->lot_no !== $before) {
                DB::table('lot_title_log')->insert([
                    'lot_idx' => $lotIdx, 'before_no' => $before, 'after_no' => $lot->lot_no,
                    'changed_by' => Auth::id() ?? 0, 'changed_at' => now(),
                ]);
            }
            return $lot;
        });
    }

    // 다른 LOT 의 원번호 · 표시 번호와 겹치면 막는다 ( 스캔 · 검색이 한 LOT 으로만 )
    public function assertTitleFree(string $title, int $exceptLotIdx = 0, string $field = 'lot_title'): void {
        $dup = Lot::where('lot_idx', '!=', $exceptLotIdx)->where(fn ($q) => $q->where('lot_no', $title)->orWhere('lot_title', $title))->first(['lot_idx', 'lot_no', 'lot_title']);
        if ($dup) {
            throw ValidationException::withMessages([$field => ["「{$title}」 은 다른 LOT( {$dup->original_no} )에서 쓰고 있습니다."]]);
        }
    }

    /**
     * LOT 전환으로 새 LOT ( 11-25 · 재고조정 [다른 품목 새 LOT] · [LOT 분리] ) — 트랜잭션 안에서
     *   번호 : 그 품목의 LOT 규칙 ( 제품 = 생산 기준 첫 규칙 · 자재 = 자재 기준 첫 규칙 — 공급처 LOT 그대로 쓰는 설정이면 규칙 없이 )
     *          규칙이 없으면 원 LOT 번호 + -S순번
     *   물려받기 : 같은 품목( 분리 ) = 공급처 · 공급처 LOT · Heat No · 조각 규격 · 입고일 / 다른 품목 = 공급처 · Heat No ( 입고일 = 처리일 )
     *   $title : 표시 번호 ( 있으면 lot_title — 겹치면 막는다 )
     */
    public function issueChangeLot(Item $item, Lot $src, string $at, ?string $title = null): Lot {
        $same = (int) $item->item_idx === (int) $src->item_idx;
        $date = substr($at, 0, 10);
        $rule = null;
        $lotNo = null;
        if ($item->item_type === 'MATERIAL') {
            if ($this->materialSource() !== 'EXTERNAL') $rule = $this->materialRules()->first();
        } else {
            $rule = $this->productionRules()->first();
        }
        if ($rule) {
            $partnerCode = $src->partner_idx ? (string) DB::table('partner')->where('partner_idx', $src->partner_idx)->value('partner_code') : '';
            $lotNo = LotRule::render($rule->format_spec ?? [], (string) $rule->separator, [
                'item_code'    => $item->item_code,
                'item_spec'    => $item->spec,
                'date'         => $date,
                'seq'          => $this->nextSeq($rule, $date),
                'partner_code' => $partnerCode,
                'with_partner' => $partnerCode !== '' && $this->partnerMode($rule) === 'ALWAYS',
            ]);
            if (Lot::where('lot_no', $lotNo)->exists()) $lotNo = null;   // 규칙에 순번이 없어 겹치면 아래 방식으로
        }
        if ($lotNo === null) {
            $base = $src->original_no . '-S';
            $seq  = (int) Lot::where('lot_no', 'like', $base . '%')->count();
            do {
                $lotNo = mb_substr($base . str_pad((string) ++$seq, 2, '0', STR_PAD_LEFT), 0, 50);
            } while (Lot::where('lot_no', $lotNo)->exists());
            $rule = null;
        }

        $title = $title !== null ? trim($title) : '';
        if ($title !== '') {
            if (mb_strlen($title) > 60) throw ValidationException::withMessages(['lot_title' => ['LOT 번호는 60자까지입니다.']]);
            if ($title === $lotNo) $title = '';
            else $this->assertTitleFree($title);
        }

        return Lot::create([
            'item_idx'        => $item->item_idx,
            'lot_no'          => mb_substr($lotNo, 0, 50),
            'lot_title'       => $title !== '' ? $title : null,
            'external_lot_no' => $same ? $src->external_lot_no : null,
            'heat_no'         => $src->heat_no,
            'partner_idx'     => $src->partner_idx,
            'spec_value'      => $same ? $src->spec_value : null,
            'parent_lot_idx'  => $src->lot_idx,
            'source'          => 'CONVERT',
            'lot_rule_idx'    => $rule?->lot_rule_idx,
            'in_at'           => $same && $src->in_at ? $src->in_at : $at,
        ]);
    }

    public function enabled(): bool {
        return $this->policy->isEnabled('feature.lot');
    }

    public function materialSource(): string {
        return (string) ($this->policy->get('lot.material_source') ?? 'INTERNAL');
    }

    // 자재 기준 사용 중 규칙 ( 화면이 고른다 — 첫 규칙이 기본 )
    public function materialRules(): Collection {
        return LotRule::where('rule_type', 'MATERIAL')->where('is_use', 'Y')->orderBy('lot_rule_idx')->get();
    }

    // 규칙의 거래처 요소 방식 : ALWAYS · ASK · null( 요소 없음 )
    public function partnerMode(LotRule $rule): ?string {
        foreach ($rule->format_spec ?? [] as $el) {
            if (($el['type'] ?? '') === 'PARTNER') {
                return $el['mode'] ?? 'ALWAYS';
            }
        }
        return null;
    }

    /**
     * 입고 LOT 하나 발급 ( 트랜잭션 안에서 )
     * @param array{partner_idx:int, partner_code:string, with_partner:bool, external_lot_no:?string, heat_no:?string, spec_value:?string, date:string} $ctx
     * @return Lot|null  LOT 을 쓰지 않으면 null
     */
    public function issueMaterialLot(Item $item, ?LotRule $rule, array $ctx): ?Lot {
        if (!$this->enabled()) {
            return null;
        }

        $external = trim((string) ($ctx['external_lot_no'] ?? '')) ?: null;

        if ($this->materialSource() === 'EXTERNAL') {
            if ($external === null) {
                throw ValidationException::withMessages(['external_lot_no' => ['공급처 LOT 번호를 넣으세요. ( 공급처 LOT 을 그대로 쓰는 설정 )']]);
            }
            $lotNo = $external;
            $rule  = null;
        } else {
            if (!$rule) {
                throw ValidationException::withMessages(['lot_rule_idx' => ['LOT규칙관리에서 자재 기준 LOT 규칙을 등록하세요.']]);
            }
            $lotNo = LotRule::render($rule->format_spec ?? [], (string) $rule->separator, [
                'item_code'    => $item->item_code,
                'item_spec'    => $item->spec,
                'date'         => $ctx['date'],
                'seq'          => $this->nextSeq($rule, $ctx['date']),
                'partner_code' => $ctx['partner_code'] ?? '',
                'with_partner' => !empty($ctx['with_partner']),
            ]);
        }

        return Lot::create([
            'item_idx'        => $item->item_idx,
            'lot_no'          => mb_substr($lotNo, 0, 50),
            'external_lot_no' => $external,
            'heat_no'         => trim((string) ($ctx['heat_no'] ?? '')) ?: null,   // 공급처 LOT 과 따로 ( 11-12 )
            'partner_idx'     => $ctx['partner_idx'] ?? null,
            'spec_value'      => $ctx['spec_value'] ?? null,
            'source'          => 'PURCHASE',
            'lot_rule_idx'    => $rule?->lot_rule_idx,
        ]);
    }

    // 생산 기준 사용 중 규칙 ( 생산일 · 종료일 ) — 작업지시가 고른다
    public function productionRules(): Collection {
        return LotRule::whereIn('rule_type', ['PRODUCE_START', 'PRODUCE_END'])->where('is_use', 'Y')
            ->orderBy('rule_type')->orderBy('lot_rule_idx')->get();
    }

    /**
     * 생산 LOT 발급 ( 작업지시 저장 때 — 50-생산 §5 ). 트랜잭션 안에서
     *   번호의 날짜 : 생산일 기준 = 착수 예정일 / 종료일 기준 = 생산 기한 ( 화면이 골라 넘긴다 )
     *   거래처 요소는 생산 기준에 없다 ( BAS-10 )
     * @return Lot|null  LOT 을 쓰지 않으면 null
     */
    public function issueProductionLot(Item $item, ?LotRule $rule, string $date): ?Lot {
        if (!$this->enabled()) {
            return null;
        }
        if (!$rule) {
            throw ValidationException::withMessages(['lot_rule_idx' => ['LOT규칙관리에서 생산 기준( 생산일 · 종료일 ) LOT 규칙을 등록하세요.']]);
        }

        $lotNo = LotRule::render($rule->format_spec ?? [], (string) $rule->separator, [
            'item_code' => $item->item_code,
            'item_spec' => $item->spec,
            'date'      => $date,
            'seq'       => $this->nextSeq($rule, $date),
        ]);

        return Lot::create([
            'item_idx'     => $item->item_idx,
            'lot_no'       => mb_substr($lotNo, 0, 50),
            'source'       => 'PRODUCTION',
            'lot_rule_idx' => $rule->lot_rule_idx,
        ]);
    }

    // 리셋 구간 = 규칙의 날짜 단위 ( 날짜 요소가 없으면 리셋하지 않음 )
    public function periodKey(LotRule $rule, string $date): string {
        $ts = strtotime($date);
        foreach ($rule->format_spec ?? [] as $el) {
            if (($el['type'] ?? '') === 'DATE') {
                return match ($el['unit'] ?? '') {
                    'YY'     => date('y', $ts),
                    'YYMM'   => date('ym', $ts),
                    'YYMMDD' => date('ymd', $ts),
                    default  => 'ALL',
                };
            }
        }
        return 'ALL';
    }

    private function nextSeq(LotRule $rule, string $date): int {
        $key = $this->periodKey($rule, $date);
        DB::table('lot_seq')->insertOrIgnore(['lot_rule_idx' => $rule->lot_rule_idx, 'period_key' => $key, 'last_seq' => 0]);

        $row = DB::table('lot_seq')
            ->where('lot_rule_idx', $rule->lot_rule_idx)->where('period_key', $key)
            ->lockForUpdate()->first();
        $seq = (int) $row->last_seq + 1;

        DB::table('lot_seq')
            ->where('lot_rule_idx', $rule->lot_rule_idx)->where('period_key', $key)
            ->update(['last_seq' => $seq]);

        return $seq;
    }
}

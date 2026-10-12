<?php

namespace MesCore\Stock\Services;

use Illuminate\Support\Collection;
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
     * @param array{partner_idx:int, partner_code:string, with_partner:bool, external_lot_no:?string, spec_value:?string, date:string} $ctx
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

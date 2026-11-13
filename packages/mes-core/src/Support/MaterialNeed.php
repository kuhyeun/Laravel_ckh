<?php

namespace MesCore\Support;

/**
 * 자재 필요량 — 한곳에서 ( BOM 생산 가능 수량 · 11-12 )
 *   소요량 방식      : 수량 × 개당 소요 ( qty )
 *   생산 가능 수량 방식 : 올림( 수량 ÷ yield ) — 자재 1개로 제품 yield 개 ( 봉재 · 판재 ) · 덜 쓰는 일은 없으므로 항상 올림
 *   다단계는 한 단계씩 : 위 품목의 필요량을 구한 뒤 그 수량으로 아래 자재를 구한다 ( 개당 값을 미리 곱해 두지 않는다 )
 *   쓰는 곳 : 작업지시 자재 · 자재출고 계획 · 발주 필요량 · 반제품 함께 지시 · BOM 트리( 화면은 Utils/materialNeed.js )
 */
final class MaterialNeed {

    private const EPS = 0.000001;

    public static function of(float $targetQty, float $qtyPer, $yieldQty = null): float {
        $yield = (float) ($yieldQty ?? 0);
        if ($yield > 0) {
            return (float) ceil($targetQty / $yield - self::EPS);
        }
        return $targetQty * $qtyPer;
    }

    // 개당으로 본 값 ( 비율 계산용 — 주 자재 · 대체 자재 환산 ) : 생산 가능 수량이면 1 ÷ yield
    public static function perUnit(float $qtyPer, $yieldQty = null): float {
        $yield = (float) ($yieldQty ?? 0);
        return $yield > 0 ? 1 / $yield : $qtyPer;
    }

    // 생산 가능 수량으로 넣은 줄의 qty 칸 값 ( 1 ÷ yield · 소수 4자리 · 0 이 되지 않게 ) — 표시 · 예전 코드 호환용
    public static function qtyOfYield($yieldQty): string {
        return number_format(max(0.0001, round(1 / (float) $yieldQty, 4)), 4, '.', '');
    }
}

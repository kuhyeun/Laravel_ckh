<?php

namespace MesCore\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\Attachment;
use MesCore\Basic\Services\AttachmentService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Config\Services\RoutingService;
use MesCore\Config\Services\BomService;
use MesCore\Order\Models\SalesOrder;
use MesCore\Order\Models\SalesOrderLine;

/**
 * 수주 저장 · 채번 · 삭제 ( 트랜잭션 경계 ) — docs/screens/30-수주.md
 *
 *   저장 : 머리 + 줄 한 트랜잭션. 요청에 없는 기존 줄은 지우고, 기존 줄 IDX 는 유지 ( 작업지시 · 출하가 가리킨다 )
 *   금액 : 줄 금액 = 수량 × 단가( 원 단위 반올림 ), 머리 합계 = 줄 금액 합 — 서버가 계산한다
 *   잠금 : 아래 단계( 생산 · 출하 )가 붙은 줄은 품목 변경 · 삭제 불가, 수량은 생산 · 출하량 아래로 못 줄임 ( §6 )
 *   삭제 : 아래 단계가 없으면 지운다( 줄 · 첨부까지 ), 있으면 취소 상태로
 *   공정 : 줄마다 수주 라우팅( ③ )을 항상 만든다 — 품목 라우팅( ② ) 복사 + 이 수주에서 고친 공정 · 변경 이력 ( docs/라우팅-3단계-설계.md )
 *          그 줄로 작업지시( 취소 제외 )가 생기면 공정은 잠긴다
 *   자재 : 줄마다 수주 자재( 품목 BOM 복사 + 대체 자재 )를 항상 만든다 — 공정과 같은 규칙( 잠금 · 이력 ) · docs/대체자재-수주자재-설계.md
 *          투입 지점은 수주 공정 줄 ( 화면은 수주 공정의 순서 routing_seq 로 보낸다 — 공정을 먼저 저장하고 잇는다 )
 */
class SalesOrderService {

    public const NO_PREFIX = 'SO-';

    public function __construct(private AttachmentService $attachments, private RoutingService $routings, private BomService $boms) {}

    /**
     * @param array $data 검증된 요청 ( 머리 + lines[] )
     * @return array{0: SalesOrder, 1: string[]} [ 저장된 수주, 경고 ]
     */
    public function save(array $data, ?SalesOrder $order): array {
        $order    = $order ?? new SalesOrder();
        $existing = $order->exists ? $order->lines()->get()->keyBy('sales_order_line_idx') : collect();
        $lines    = array_values($data['lines']);
        $errors   = [];
        $warnings = [];

        // 고객사 : 고객사 구분이 켜진 사용 중 거래처 ( 수정할 때 원래 값이면 통과 )
        $partnerChanged = (int) $order->partner_idx !== (int) $data['partner_idx'];
        if ($partnerChanged) {
            $partner = Partner::find($data['partner_idx']);
            if (!$partner || $partner->is_customer !== 'Y' || $partner->is_use !== 'Y') {
                $errors['partner_idx'] = ['고객사로 쓸 수 있는 사용 중 거래처가 아닙니다.'];   // E_ORDER_PARTNER_NOT_CUSTOMER
            } elseif ($order->exists && $existing->contains(fn ($l) => $l->hasDownstream())) {
                $errors['partner_idx'] = ['생산 · 출하가 시작된 수주는 고객사를 바꿀 수 없습니다.'];   // E_ORDER_LINE_LOCKED
            }
        }

        $items = Item::whereIn('item_idx', array_column($lines, 'item_idx'))->get()->keyBy('item_idx');
        $keep  = [];
        foreach ($lines as $i => $row) {
            $old  = !empty($row['sales_order_line_idx']) ? $existing->get($row['sales_order_line_idx']) : null;
            $item = $items->get($row['item_idx']);
            if (!empty($row['sales_order_line_idx']) && !$old) {
                $errors["lines.{$i}.item_idx"] = ['이 수주의 줄이 아닙니다. 다시 열어 주세요.'];
                continue;
            }
            if ($old) {
                $keep[] = $old->sales_order_line_idx;
            }

            // 품목 : 제품 대분류의 사용 중 품목 ( 기존 줄의 원래 품목이면 통과 )
            $sameItem = $old && (int) $old->item_idx === (int) $row['item_idx'];
            if (!$sameItem && (!$item || $item->item_type !== 'PRODUCT' || $item->is_use !== 'Y')) {
                $errors["lines.{$i}.item_idx"] = ['수주할 수 있는 품목이 아닙니다. ( 제품 대분류의 사용 중 품목 )'];   // E_ORDER_ITEM_NOT_PRODUCT
                continue;
            }

            // 아래 단계가 붙은 줄
            if ($old && $old->hasDownstream()) {
                if (!$sameItem) {
                    $errors["lines.{$i}.item_idx"] = ['생산 · 출하가 시작된 줄은 품목을 바꿀 수 없습니다.'];
                } elseif ((float) $row['qty'] < $old->minQty()) {
                    $errors["lines.{$i}.qty"] = ['생산 · 출하( 출하대기 포함 )된 수량( ' . $this->num($old->minQty()) . ' )보다 줄일 수 없습니다.'];
                }
            }

            // 경고
            $label = $item ? "{$item->item_code}" : '#' . ($i + 1);
            if ($row['due_date'] < $data['order_date']) {
                $warnings[] = "{$label} : 납품일이 수주일보다 앞입니다.";   // W_DUE_BEFORE_ORDER
            } elseif (($data['status'] ?? 'CONFIRMED') === 'CONFIRMED' && $row['due_date'] < now()->toDateString()
                && (!$old || $old->due_date?->toDateString() !== $row['due_date'])) {
                $warnings[] = "{$label} : 납품일이 이미 지났습니다.";   // W_DUE_PAST ( 바꾼 줄만 )
            }
            if ($item && $item->item_kind === 'SEMI' && !$sameItem) {
                $warnings[] = "{$label} : 반제품입니다. 반제품 출하는 예외적인 경우입니다.";   // W_ITEM_SEMI
            }
            if ((float) ($row['unit_price'] ?? 0) == 0) {
                $warnings[] = "{$label} : 단가가 0 입니다.";   // W_PRICE_ZERO
            }

            // 공정 : 작업지시가 생긴 줄은 잠김 · 검사 · 선행 승인 규칙은 라우팅과 같다 ( 공정 없음은 허용 — 작업지시에서 넣는다 )
            if (isset($row['routing_lines'])) {
                if ($old && $old->directedQty() > 0) {
                    $errors["lines.{$i}.routing_lines"] = ['작업지시가 생성된 줄의 공정은 바꿀 수 없습니다.'];
                } else {
                    try {
                        $own = $old ? $this->routings->orderLineRouting($old->sales_order_line_idx) : null;
                        foreach ($this->routings->assertLines(array_values($row['routing_lines']), $own, "lines.{$i}.routing_lines", true) as $w) {
                            $warnings[] = "{$label} : {$w}";
                        }
                    } catch (ValidationException $e) {
                        $errors += $e->errors();
                    }
                }
            }

            // 자재 : 공정과 같은 잠금 · 자기 자신 · 순환 · 대체 자재 규칙
            if (isset($row['material_lines'])) {
                if ($old && $old->directedQty() > 0) {
                    $errors["lines.{$i}.material_lines"] = ['작업지시가 생성된 줄의 자재는 바꿀 수 없습니다.'];
                } else {
                    try {
                        $this->boms->assertLines((int) $row['item_idx'], array_values($row['material_lines']), "lines.{$i}.material_lines");
                    } catch (ValidationException $e) {
                        $errors += $e->errors();
                    }
                }
            }
        }

        // 지워지는 기존 줄 중 아래 단계가 붙은 것
        foreach ($existing as $idx => $old) {
            if (!in_array($idx, $keep, true) && $old->hasDownstream()) {
                $errors['lines'] = ["생산 · 출하가 시작된 줄( {$old->item?->item_code} )은 지울 수 없습니다. 수량을 줄이세요."];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($order, $data, $lines, $existing) {
            $order->fill([
                'order_date'     => $data['order_date'],
                'partner_idx'    => $data['partner_idx'],
                'order_name'     => $data['order_name'] ?? null,
                'customer_po_no' => $data['customer_po_no'] ?? null,
                'remark'         => $data['remark'] ?? null,
            ]);
            if (in_array($data['status'] ?? null, SalesOrder::EDITABLE_STATUSES, true)
                && in_array($order->status ?? 'CONFIRMED', SalesOrder::EDITABLE_STATUSES, true)) {
                $order->status = $data['status'];
            }
            if (!$order->exists) {
                $order->order_no = $this->nextOrderNo($data['order_date']);
            }
            $order->save();

            $total = 0;
            $keep  = [];
            foreach ($lines as $n => $row) {
                $line = !empty($row['sales_order_line_idx'])
                    ? $existing->get($row['sales_order_line_idx'])
                    : new SalesOrderLine(['sales_order_idx' => $order->sales_order_idx]);

                // decimal 캐스트에는 문자열로 넘긴다 ( float 은 brick/math 에서 경고 )
                $price  = (float) ($row['unit_price'] ?? 0);
                $amount = round((float) $row['qty'] * $price, 0);
                $line->fill([
                    'line_no'    => $n + 1,
                    'item_idx'   => $row['item_idx'],
                    'qty'        => (string) $row['qty'],
                    'unit_price' => (string) $price,
                    'amount'     => (string) $amount,
                    'due_date'   => $row['due_date'],
                    'remark'     => $row['remark'] ?? null,
                ])->save();

                // 수주 라우팅( ③ ) — 보낸 공정이 없으면 그대로( 없거나 품목을 바꿨으면 품목 라우팅 복사 )
                $routingLines = isset($row['routing_lines']) ? array_values($row['routing_lines']) : null;
                $this->routings->syncOrderLineRouting($line, $order->order_no, $routingLines);

                // 수주 자재 — 공정 다음 ( 투입 지점이 수주 공정 줄을 가리킨다 )
                $materialLines = isset($row['material_lines']) ? array_values($row['material_lines']) : null;
                $this->boms->syncOrderLineBom($line, $order->order_no, $materialLines);

                $total += $amount;
                $keep[] = $line->sales_order_line_idx;
            }

            SalesOrderLine::where('sales_order_idx', $order->sales_order_idx)
                ->whereNotIn('sales_order_line_idx', $keep ?: [0])
                ->delete();

            $order->total_amount = (string) $total;
            $order->save();
        });

        return [$order->fresh(['partner', 'lines.item']), array_values(array_unique($warnings))];
    }

    // SO-YYMMDD-NNN ( 수주일 기준 날짜별 순번 ). 같은 날짜의 마지막 번호를 잠그고 +1
    public function nextOrderNo(string $orderDate): string {
        $prefix = self::NO_PREFIX . date('ymd', strtotime($orderDate)) . '-';
        $last   = SalesOrder::where('order_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('order_no')
            ->value('order_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return string 'deleted' | 'canceled'
     */
    public function delete(SalesOrder $order): string {
        $order->loadMissing('lines');

        if ($order->hasDownstream()) {
            $order->status = 'CANCELED';
            $order->save();
            return 'canceled';
        }

        DB::transaction(function () use ($order) {
            Attachment::where('ref_type', 'sales_order')->where('ref_idx', $order->sales_order_idx)->get()
                ->each(fn (Attachment $a) => $this->attachments->delete($a));
            $order->delete();   // 줄은 FK cascade
        });
        return 'deleted';
    }

    // 상태 바꾸기 ( 목록의 [마감] · 수정 화면의 [다시 열기] )
    public function changeStatus(SalesOrder $order, string $to): void {
        $allowed = [
            'CLOSED'    => ['CONFIRMED', 'DRAFT'],
            'CONFIRMED' => ['CLOSED', 'CANCELED'],
        ];
        if (!in_array($order->status, $allowed[$to] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => [SalesOrder::STATUSES[$order->status] . ' 상태에서는 ' . SalesOrder::STATUSES[$to] . '(으)로 바꿀 수 없습니다.'],
            ]);
        }
        $order->status = $to;
        $order->save();
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

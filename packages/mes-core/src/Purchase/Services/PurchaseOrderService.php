<?php

namespace MesCore\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\Attachment;
use MesCore\Basic\Services\AttachmentService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Purchase\Models\PurchaseOrder;
use MesCore\Purchase\Models\PurchaseOrderLine;

/**
 * 발주 저장 · 채번 · 삭제 ( 트랜잭션 경계 ) — docs/screens/40-구매자재.md
 *
 *   수주( SalesOrderService )와 같은 규칙. 다른 것
 *     거래처 = 구매처 구분 · 사용 중 / 품목 = 자재 대분류( 제품은 경고 후 허용 )
 *     아래 단계 = 입고 ( 입고된 줄은 품목 변경 · 삭제 불가, 수량은 입고량 아래로 못 줄임 )
 */
class PurchaseOrderService {

    public const NO_PREFIX = 'PO-';

    public function __construct(private AttachmentService $attachments) {}

    /**
     * @param array $data 검증된 요청 ( 머리 + lines[] )
     * @return array{0: PurchaseOrder, 1: string[]} [ 저장된 발주, 경고 ]
     */
    public function save(array $data, ?PurchaseOrder $order): array {
        $order    = $order ?? new PurchaseOrder();
        $existing = $order->exists ? $order->lines()->get()->keyBy('purchase_order_line_idx') : collect();
        $lines    = array_values($data['lines']);
        $errors   = [];
        $warnings = [];

        // 구매처 : 구매처 구분이 켜진 사용 중 거래처 ( 수정할 때 원래 값이면 통과 )
        if ((int) $order->partner_idx !== (int) $data['partner_idx']) {
            $partner = Partner::find($data['partner_idx']);
            if (!$partner || $partner->is_supplier !== 'Y' || $partner->is_use !== 'Y') {
                $errors['partner_idx'] = ['구매처로 쓸 수 있는 사용 중 거래처가 아닙니다.'];   // E_PO_PARTNER_NOT_SUPPLIER
            } elseif ($order->exists && $existing->contains(fn ($l) => $l->hasDownstream())) {
                $errors['partner_idx'] = ['입고가 시작된 발주는 구매처를 바꿀 수 없습니다.'];   // E_PO_LINE_LOCKED
            }
        }

        $items = Item::whereIn('item_idx', array_column($lines, 'item_idx'))->get()->keyBy('item_idx');
        $keep  = [];
        foreach ($lines as $i => $row) {
            $old  = !empty($row['purchase_order_line_idx']) ? $existing->get($row['purchase_order_line_idx']) : null;
            $item = $items->get($row['item_idx']);
            if (!empty($row['purchase_order_line_idx']) && !$old) {
                $errors["lines.{$i}.item_idx"] = ['이 발주의 줄이 아닙니다. 다시 열어 주세요.'];
                continue;
            }
            if ($old) {
                $keep[] = $old->purchase_order_line_idx;
            }

            // 품목 : 사용 중 품목 ( 자재 · 제품 — 제품은 경고 ). 기존 줄의 원래 품목이면 통과
            $sameItem = $old && (int) $old->item_idx === (int) $row['item_idx'];
            if (!$sameItem && (!$item || $item->is_use !== 'Y')) {
                $errors["lines.{$i}.item_idx"] = ['발주할 수 있는 품목이 아닙니다. ( 사용 중 품목 )'];   // E_PO_ITEM_NOT_MATERIAL
                continue;
            }

            if ($old && $old->hasDownstream()) {
                if (!$sameItem) {
                    $errors["lines.{$i}.item_idx"] = ['입고가 시작된 줄은 품목을 바꿀 수 없습니다.'];
                } elseif ((float) $row['qty'] < $old->minQty()) {
                    $errors["lines.{$i}.qty"] = ['입고된 수량( ' . $this->num($old->minQty()) . ' )보다 줄일 수 없습니다.'];
                }
            }

            $label = $item ? $item->item_code : '#' . ($i + 1);
            if ($row['due_date'] < $data['order_date']) {
                $warnings[] = "{$label} : 입고 예정일이 발주일보다 앞입니다.";   // W_DUE_BEFORE_ORDER
            } elseif (($data['status'] ?? 'CONFIRMED') === 'CONFIRMED' && $row['due_date'] < now()->toDateString()
                && (!$old || $old->due_date?->toDateString() !== $row['due_date'])) {
                $warnings[] = "{$label} : 입고 예정일이 이미 지났습니다.";   // W_DUE_PAST ( 바꾼 줄만 )
            }
            if ($item && $item->item_type === 'PRODUCT' && !$sameItem) {
                $warnings[] = "{$label} : 제품 품목입니다. 사서 쓰는 상품 · 반제품이면 정상입니다.";   // W_PO_ITEM_PRODUCT
            }
            if ((float) ($row['unit_price'] ?? 0) == 0) {
                $warnings[] = "{$label} : 단가가 0 입니다.";   // W_PRICE_ZERO
            }
        }

        foreach ($existing as $idx => $old) {
            if (!in_array($idx, $keep, true) && $old->hasDownstream()) {
                $errors['lines'] = ["입고가 시작된 줄( {$old->item?->item_code} )은 지울 수 없습니다. 수량을 줄이세요."];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($order, $data, $lines, $existing) {
            $order->fill([
                'order_date'  => $data['order_date'],
                'partner_idx' => $data['partner_idx'],
                'order_name'  => $data['order_name'] ?? null,
                'remark'      => $data['remark'] ?? null,
            ]);
            if (in_array($data['status'] ?? null, PurchaseOrder::EDITABLE_STATUSES, true)
                && in_array($order->status ?? 'CONFIRMED', PurchaseOrder::EDITABLE_STATUSES, true)) {
                $order->status = $data['status'];
            }
            if (!$order->exists) {
                $order->order_no = $this->nextOrderNo($data['order_date']);
            }
            $order->save();

            $total = 0;
            $keep  = [];
            foreach ($lines as $n => $row) {
                $line = !empty($row['purchase_order_line_idx'])
                    ? $existing->get($row['purchase_order_line_idx'])
                    : new PurchaseOrderLine(['purchase_order_idx' => $order->purchase_order_idx]);

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

                $total += $amount;
                $keep[] = $line->purchase_order_line_idx;
            }

            PurchaseOrderLine::where('purchase_order_idx', $order->purchase_order_idx)
                ->whereNotIn('purchase_order_line_idx', $keep ?: [0])
                ->delete();

            $order->total_amount = (string) $total;
            $order->save();
        });

        return [$order->fresh(['partner', 'lines.item']), array_values(array_unique($warnings))];
    }

    // PO-YYMMDD-NNN ( 발주일 기준 날짜별 순번 ). 같은 날짜의 마지막 번호를 잠그고 +1
    public function nextOrderNo(string $orderDate): string {
        $prefix = self::NO_PREFIX . date('ymd', strtotime($orderDate)) . '-';
        $last   = PurchaseOrder::where('order_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('order_no')
            ->value('order_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return string 'deleted' | 'canceled'
     */
    public function delete(PurchaseOrder $order): string {
        $order->loadMissing('lines');

        if ($order->hasDownstream()) {
            $order->status = 'CANCELED';
            $order->save();
            return 'canceled';
        }

        DB::transaction(function () use ($order) {
            Attachment::where('ref_type', 'purchase_order')->where('ref_idx', $order->purchase_order_idx)->get()
                ->each(fn (Attachment $a) => $this->attachments->delete($a));
            $order->delete();   // 줄은 FK cascade
        });
        return 'deleted';
    }

    // 상태 바꾸기 ( 목록의 [마감] · 수정 화면의 [다시 열기] )
    public function changeStatus(PurchaseOrder $order, string $to): void {
        $allowed = [
            'CLOSED'    => ['CONFIRMED', 'DRAFT'],
            'CONFIRMED' => ['CLOSED', 'CANCELED'],
        ];
        if (!in_array($order->status, $allowed[$to] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => [PurchaseOrder::STATUSES[$order->status] . ' 상태에서는 ' . PurchaseOrder::STATUSES[$to] . '(으)로 바꿀 수 없습니다.'],
            ]);
        }
        $order->status = $to;
        $order->save();
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}

<?php

namespace MesCore\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\Attachment;

/**
 * 수주 · 발주 머리의 삭제 · 상태 바꾸기 ( 11-09 코드 검사 C-5 — SalesOrderService · PurchaseOrderService 에 같은 코드가 있었다 )
 *   쓰는 클래스 : $this->attachments ( AttachmentService ) · 모델에 STATUSES · lines · hasDownstream() · 줄에 hasReference()
 */
trait OrderHeaderActions {

    /**
     * 아래 단계가 붙었거나 다른 표가 줄을 가리키면( 취소분 포함 ) 지우지 않고 취소 / 아니면 첨부와 함께 지운다 ( 줄은 FK cascade )
     * @return string 'deleted' | 'canceled'
     */
    protected function deleteOrCancel(Model $order, string $attachRef): string {
        $order->loadMissing('lines');
        if ($order->hasDownstream() || $order->lines->contains(fn ($l) => $l->hasReference())) {
            $order->status = 'CANCELED';
            $order->save();
            return 'canceled';
        }
        DB::transaction(function () use ($order, $attachRef) {
            Attachment::where('ref_type', $attachRef)->where('ref_idx', $order->getKey())->get()
                ->each(fn (Attachment $a) => $this->attachments->delete($a));
            $order->delete();
        });
        return 'deleted';
    }

    // 상태 바꾸기 ( 목록의 [마감] · 수정 화면의 [다시 열기] ) — 마감 = 미확정 · 확정에서 / 다시 열기 = 완료 · 취소에서
    protected function changeHeaderStatus(Model $order, string $to): void {
        $allowed = [
            'CLOSED'    => ['CONFIRMED', 'DRAFT'],
            'CONFIRMED' => ['CLOSED', 'CANCELED'],
        ];
        if (!in_array($order->status, $allowed[$to] ?? [], true)) {
            $labels = $order::STATUSES;
            throw ValidationException::withMessages([
                'status' => [$labels[$order->status] . ' 상태에서는 ' . $labels[$to] . '(으)로 바꿀 수 없습니다.'],
            ]);
        }
        $order->status = $to;
        $order->save();
    }

    /**
     * 날짜만 고치기 ( 완료된 수주 · 발주의 [수정] · 11-25 ) — 머리 날짜 + 줄마다 예정일. 상태 · 번호 · 수량 · 단가는 그대로
     *   보낸 칸 : order_date · due_{줄 IDX} ( DateEditDialog 의 field key ) — 보내지 않은 칸은 그대로
     *   취소된 건은 막는다 · 머리와 줄을 잠그고 바꾼다 · 바꾸기 전 값은 활동 로그에 ( DateEdit::remember )
     * @param array $labels ['order' => '수주일', 'due' => '납품일', 'noun' => '수주']
     * @return string[] 경고 ( 예정일이 머리 날짜보다 앞 — 막지 않는다 )
     */
    protected function editHeaderDates(Model $order, array $input, array $labels): array {
        return DB::transaction(function () use ($order, $input, $labels) {
            $order = $order::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($order->status === 'CANCELED') {
                throw ValidationException::withMessages(['status' => ["취소된 {$labels['noun']}는 고칠 수 없습니다."]]);
            }
            $lines = $order->lines()->with('item:item_idx,item_code')->lockForUpdate()->get();

            $before = ['order_date' => $order->order_date?->toDateString()];
            foreach ($lines as $l) {
                $before['due_' . $l->getKey()] = $l->due_date?->toDateString();
            }
            DateEdit::remember($before);

            if (array_key_exists('order_date', $input)) {
                $order->order_date = DateEdit::date($input['order_date'], 'order_date', $labels['order'])->toDateString();
            }
            $orderDate = $order->order_date?->toDateString();

            $warnings = [];
            foreach ($lines as $l) {
                $key = 'due_' . $l->getKey();
                if (array_key_exists($key, $input)) {
                    $l->due_date = DateEdit::date($input[$key], $key, $labels['due'])->toDateString();
                }
                $due = $l->due_date?->toDateString();
                if ($due && $orderDate && $due < $orderDate) {
                    $warnings[] = ($l->item?->item_code ?? '#' . $l->line_no) . " : {$labels['due']}이 {$labels['order']}보다 앞입니다.";   // W_DUE_BEFORE_ORDER
                }
                if ($l->isDirty('due_date')) {
                    $l->save();
                }
            }
            if ($order->isDirty('order_date')) {
                $order->save();
            }
            return $warnings;
        });
    }
}

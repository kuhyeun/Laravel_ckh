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
}

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Purchase\Controllers\PurchaseOrderController;
use MesCore\Purchase\Controllers\ReceiptController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 구매 ( 자재관리 모듈 — config('mes.modules') 의 Material ) 데이터 엔드포인트
//   페이지 렌더는 제네릭 라우터( url_path → page_component )
//   권한 : 조회 can_read / 등록 · 수정 · 마감 · 다시 열기 can_write / 삭제( 입고가 있으면 취소 ) can_delete

Route::prefix('purchase')->name('purchase.')->middleware([UserAuth::class])->group(function() {

    // 발주서관리 ( Purchase/PurchaseOrderManage )
    Route::prefix('order')->name('order.')->group(function() {
        $read   = MenuAuth::class . ':Purchase/PurchaseOrderManage,can_read';
        $write  = MenuAuth::class . ':Purchase/PurchaseOrderManage,can_write';
        $delete = MenuAuth::class . ':Purchase/PurchaseOrderManage,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [PurchaseOrderController::class, 'list'])->name('list');
            Route::get('/show/{orderIdx}', [PurchaseOrderController::class, 'show'])->whereNumber('orderIdx')->name('show');
            Route::get('/formData', [PurchaseOrderController::class, 'formData'])->name('formData');
        });

        Route::middleware($write)->group(function() {
            Route::post('/save', [PurchaseOrderController::class, 'save'])->name('save');
            Route::post('/status', [PurchaseOrderController::class, 'status'])->name('status');
        });

        Route::middleware($delete)->group(function() {
            Route::post('/delete', [PurchaseOrderController::class, 'delete'])->name('del');
        });
    });

    // 입고관리 ( Purchase/ReceiptManage ) — 수정 없음, 줄 취소( 역분개 )는 쓰기 권한. 검사완료는 /quality/incoming ( 같은 창 )
    Route::prefix('receipt')->name('receipt.')->group(function() {
        $read  = MenuAuth::class . ':Purchase/ReceiptManage,can_read';
        $write = MenuAuth::class . ':Purchase/ReceiptManage,can_write';

        Route::middleware($read)->group(function() {
            Route::get('/list', [ReceiptController::class, 'list'])->name('list');
            Route::get('/show/{txnIdx}', [ReceiptController::class, 'show'])->whereNumber('txnIdx')->name('show');
            // 등록 모달 선택지 + 목록의 구매처 거르기
            Route::get('/formData', [ReceiptController::class, 'formData'])->name('formData');
        });

        Route::middleware($write)->group(function() {
            Route::get('/poLines', [ReceiptController::class, 'poLines'])->name('poLines');
            Route::get('/inspModes', [ReceiptController::class, 'inspModes'])->name('inspModes');
            Route::post('/save', [ReceiptController::class, 'save'])->name('save');
            Route::post('/cancel', [ReceiptController::class, 'cancel'])->name('cancel');
        });
    });
});

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Stock\Controllers\StockController;
use MesCore\Stock\Controllers\StockProcessController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;
use MesCore\Stock\Middleware\ItemScope;

// 제품관리 ( 제품관리 모듈 — config('mes.modules') 의 Product · Material 필요 ) 엔드포인트
//   재고 엔진 · 컨트롤러는 자재관리( routes/stock.php )와 같다 — 권한은 제품관리 메뉴, 품목은 제품만( ItemScope:PRODUCT )
//   제품입고 = 생산 입고 이력( 조회만 · 입고 / 취소는 공정실적 ) / 제품출고 = 출하 이력( 조회만 ) + 기타출고 / 창고이동은 자재관리 것을 같이 쓴다

Route::prefix('product')->name('product.')->middleware([UserAuth::class, ItemScope::class . ':PRODUCT'])->group(function() {

    // 제품입고 ( Product/ProductReceipt )
    Route::middleware(MenuAuth::class . ':Product/ProductReceipt,can_read')->get('/receipt/list', [StockProcessController::class, 'productionList'])->name('receipt.list');

    // 제품출고 ( Product/ProductIssue ) — [출하] 이력 · [기타출고] 이력 + 처리 · 취소
    Route::prefix('issue')->name('issue.')->group(function() {
        Route::middleware(MenuAuth::class . ':Product/ProductIssue,can_read')->group(function() {
            Route::get('/shipments', [StockProcessController::class, 'shipmentList'])->name('shipments');
            Route::get('/misc/list', [StockProcessController::class, 'miscList'])->name('misc.list');
            Route::get('/misc/workOrders', [StockProcessController::class, 'workOrders'])->name('misc.workOrders');
        });
        Route::middleware(MenuAuth::class . ':Product/ProductIssue,can_write')->group(function() {
            Route::post('/misc/save', [StockProcessController::class, 'miscOut'])->name('misc.save');
            Route::post('/misc/cancel/{txnIdx}', [StockProcessController::class, 'miscCancel'])->whereNumber('txnIdx')->name('misc.cancel');
        });
    });

    // 제품재고 ( Product/ProductStock )
    Route::middleware(MenuAuth::class . ':Product/ProductStock,can_read')->prefix('stock')->name('stock.')->group(function() {
        Route::get('/list', [StockController::class, 'list'])->name('list');
        Route::get('/history', [StockController::class, 'history'])->name('history');
        Route::get('/warehouses', [StockController::class, 'warehouses'])->name('warehouses');
    });

    // 제품 재고조정 ( Product/ProductAdjust )
    Route::prefix('adjust')->name('adjust.')->group(function() {
        Route::middleware(MenuAuth::class . ':Product/ProductAdjust,can_read')->group(function() {
            Route::get('/list', [StockProcessController::class, 'adjustList'])->name('list');
            Route::get('/reasons', [StockProcessController::class, 'reasons'])->name('reasons');
        });
        Route::middleware(MenuAuth::class . ':Product/ProductAdjust,can_write')->group(function() {
            Route::post('/save', [StockProcessController::class, 'adjust'])->name('save');
            Route::post('/cancel/{txnIdx}', [StockProcessController::class, 'adjustCancel'])->whereNumber('txnIdx')->name('cancel');
        });
    });
});

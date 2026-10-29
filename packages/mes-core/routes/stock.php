<?php

use Illuminate\Support\Facades\Route;
use MesCore\Stock\Controllers\StockController;
use MesCore\Stock\Controllers\StockProcessController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 재고 ( 자재관리 모듈 — config('mes.modules') 의 Material ) 엔드포인트
//   재고를 바꾸는 API 는 각 처리 화면 메뉴 권한 아래에 둔다 — 모두 StockService 를 거친다

Route::prefix('stock')->name('stock.')->middleware([UserAuth::class])->group(function() {

    // LOT 재고현황 ( Stock/StockStatus )
    Route::middleware(MenuAuth::class . ':Stock/StockStatus,can_read')->group(function() {
        Route::get('/list', [StockController::class, 'list'])->name('list');
        Route::get('/history', [StockController::class, 'history'])->name('history');
        Route::get('/warehouses', [StockController::class, 'warehouses'])->name('warehouses');
    });

    // 잔고 한 행 ( 이동 · 조정 창 ) — 재고현황 · 창고이동 · 재고조정 어느 쪽 권한으로도 ( 컨트롤러가 검사 )
    Route::get('/balance/{balanceIdx}', [StockController::class, 'balance'])->whereNumber('balanceIdx')->name('balance');

    // 창고이동 ( Stock/StockMove )
    Route::prefix('move')->name('move.')->group(function() {
        Route::middleware(MenuAuth::class . ':Stock/StockMove,can_read')->get('/list', [StockProcessController::class, 'moveList'])->name('list');
        Route::middleware(MenuAuth::class . ':Stock/StockMove,can_write')->group(function() {
            Route::post('/save', [StockProcessController::class, 'move'])->name('save');
            Route::post('/cancel/{txnIdx}', [StockProcessController::class, 'moveCancel'])->whereNumber('txnIdx')->name('cancel');
        });
    });

    // 재고조정 ( Stock/StockAdjust — 관리자에게만 메뉴 권한을 연다 )
    Route::prefix('adjust')->name('adjust.')->group(function() {
        Route::middleware(MenuAuth::class . ':Stock/StockAdjust,can_read')->group(function() {
            Route::get('/list', [StockProcessController::class, 'adjustList'])->name('list');
            Route::get('/reasons', [StockProcessController::class, 'reasons'])->name('reasons');
        });
        Route::middleware(MenuAuth::class . ':Stock/StockAdjust,can_write')->group(function() {
            Route::post('/save', [StockProcessController::class, 'adjust'])->name('save');
            Route::post('/cancel/{txnIdx}', [StockProcessController::class, 'adjustCancel'])->whereNumber('txnIdx')->name('cancel');
        });
    });

    // 기타출고 ( 재고관리 LOT 창의 [출고] — 권한 = 출고관리 Production/MaterialIssue ) · 이력은 출고관리의 [기타출고] 탭
    Route::prefix('misc')->name('misc.')->group(function() {
        Route::middleware(MenuAuth::class . ':Production/MaterialIssue,can_read')->group(function() {
            Route::get('/list', [StockProcessController::class, 'miscList'])->name('list');
            Route::get('/workOrders', [StockProcessController::class, 'workOrders'])->name('workOrders');
        });
        Route::middleware(MenuAuth::class . ':Production/MaterialIssue,can_write')->group(function() {
            Route::post('/save', [StockProcessController::class, 'miscOut'])->name('save');
            Route::post('/cancel/{txnIdx}', [StockProcessController::class, 'miscCancel'])->whereNumber('txnIdx')->name('cancel');
        });
    });
});

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Stock\Controllers\StockController;
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
});

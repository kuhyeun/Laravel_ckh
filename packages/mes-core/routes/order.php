<?php

use Illuminate\Support\Facades\Route;
use MesCore\Order\Controllers\SalesOrderController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 수주관리 ( Order 모듈 ) 데이터 엔드포인트 — config('mes.modules') 에 Order 가 있을 때만 등록
//   페이지 렌더는 제네릭 라우터( url_path → page_component )
//   권한 : 조회 can_read / 등록 · 수정 · 마감 · 다시 열기 can_write / 삭제( 아래 단계가 있으면 취소 ) can_delete

Route::prefix('order')->name('order.')->middleware([UserAuth::class])->group(function() {

    // 수주관리 ( Order/SalesOrderManage )
    Route::prefix('sales')->name('sales.')->group(function() {
        $read   = MenuAuth::class . ':Order/SalesOrderManage,can_read';
        $write  = MenuAuth::class . ':Order/SalesOrderManage,can_write';
        $delete = MenuAuth::class . ':Order/SalesOrderManage,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [SalesOrderController::class, 'list'])->name('list');
            Route::get('/show/{orderIdx}', [SalesOrderController::class, 'show'])->whereNumber('orderIdx')->name('show');
            Route::get('/formData', [SalesOrderController::class, 'formData'])->name('formData');
        });

        Route::middleware($write)->group(function() {
            Route::post('/save', [SalesOrderController::class, 'save'])->name('save');
            Route::post('/status', [SalesOrderController::class, 'status'])->name('status');
        });

        Route::middleware($delete)->group(function() {
            Route::post('/delete', [SalesOrderController::class, 'delete'])->name('del');
        });
    });
});

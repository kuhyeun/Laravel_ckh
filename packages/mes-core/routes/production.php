<?php

use Illuminate\Support\Facades\Route;
use MesCore\Production\Controllers\WorkOrderController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 생산 ( config('mes.modules') 의 Production ) 엔드포인트 — docs/screens/50-생산.md
//   권한 : 조회 can_read / 등록 · 수정 can_write / 취소 can_delete

Route::prefix('production')->name('production.')->middleware([UserAuth::class])->group(function() {

    // 작업지시 ( Production/WorkOrderManage )
    Route::prefix('work-order')->name('workOrder.')->group(function() {
        $read   = MenuAuth::class . ':Production/WorkOrderManage,can_read';
        $write  = MenuAuth::class . ':Production/WorkOrderManage,can_write';
        $delete = MenuAuth::class . ':Production/WorkOrderManage,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [WorkOrderController::class, 'list'])->name('list');
            Route::get('/show/{woIdx}', [WorkOrderController::class, 'show'])->whereNumber('woIdx')->name('show');
            Route::get('/formData', [WorkOrderController::class, 'formData'])->name('formData');
            Route::get('/routingOps/{routingIdx}', [WorkOrderController::class, 'routingOps'])->whereNumber('routingIdx')->name('routingOps');
            Route::get('/itemDefaults', [WorkOrderController::class, 'itemDefaults'])->name('itemDefaults');
            Route::get('/soLines', [WorkOrderController::class, 'soLines'])->name('soLines');
        });

        Route::middleware($write)->group(function() {
            Route::post('/save', [WorkOrderController::class, 'save'])->name('save');
        });

        Route::middleware($delete)->group(function() {
            Route::post('/cancel', [WorkOrderController::class, 'cancel'])->name('cancel');
        });
    });
});

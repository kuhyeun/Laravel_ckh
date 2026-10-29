<?php

use Illuminate\Support\Facades\Route;
use MesCore\Quality\Controllers\IncomingInspectionController;
use MesCore\Quality\Controllers\ShippingInspectionController;
use MesCore\Quality\Controllers\ProcessInspectionController;
use MesCore\Auth\Middleware\UserAuth;

// 품질 ( config('mes.modules') 의 Quality ) 엔드포인트
//   입고검사는 입고관리에서도 같은 창을 연다 → 메뉴 권한은 컨트롤러가 검사 ( 입고검사 · 입고관리 어느 쪽이든 )
//   공정검사는 공정실적에서도 같은 창을 연다 → 보기 · 판정은 공정검사 · 공정실적 어느 쪽이든
//   출고검사도 출하 창에서 같은 창을 연다 → 보기는 출고검사 · 출하관리 · 출하이력, 판정 · 취소는 출고검사 쓰기

Route::prefix('quality')->name('quality.')->middleware([UserAuth::class])->group(function() {

    Route::prefix('incoming')->name('incoming.')->group(function() {
        Route::get('/list', [IncomingInspectionController::class, 'list'])->name('list');
        Route::get('/show/{lineIdx}', [IncomingInspectionController::class, 'show'])->whereNumber('lineIdx')->name('show');
        Route::post('/complete/{lineIdx}', [IncomingInspectionController::class, 'complete'])->whereNumber('lineIdx')->name('complete');
        Route::post('/cancel/{lineIdx}', [IncomingInspectionController::class, 'cancel'])->whereNumber('lineIdx')->name('cancel');
    });

    Route::prefix('shipping')->name('shipping.')->group(function() {
        Route::get('/list', [ShippingInspectionController::class, 'list'])->name('list');
        Route::get('/formData', [ShippingInspectionController::class, 'formData'])->name('formData');
        Route::get('/show/{lineIdx}', [ShippingInspectionController::class, 'show'])->whereNumber('lineIdx')->name('show');
        Route::post('/complete/{lineIdx}', [ShippingInspectionController::class, 'complete'])->whereNumber('lineIdx')->name('complete');
        Route::post('/cancel/{lineIdx}', [ShippingInspectionController::class, 'cancel'])->whereNumber('lineIdx')->name('cancel');
    });

    Route::prefix('process')->name('process.')->group(function() {
        Route::get('/list', [ProcessInspectionController::class, 'list'])->name('list');
        Route::get('/show/{opIdx}', [ProcessInspectionController::class, 'show'])->whereNumber('opIdx')->name('show');
        Route::post('/complete/{opIdx}', [ProcessInspectionController::class, 'complete'])->whereNumber('opIdx')->name('complete');
        Route::post('/stage/{opIdx}', [ProcessInspectionController::class, 'stage'])->whereNumber('opIdx')->name('stage');
        Route::post('/cancelStage/{inspectionIdx}', [ProcessInspectionController::class, 'cancelStage'])->whereNumber('inspectionIdx')->name('cancelStage');
        Route::post('/cancel/{opIdx}', [ProcessInspectionController::class, 'cancel'])->whereNumber('opIdx')->name('cancel');
    });
});

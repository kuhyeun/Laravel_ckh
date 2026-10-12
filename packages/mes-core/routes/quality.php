<?php

use Illuminate\Support\Facades\Route;
use MesCore\Quality\Controllers\IncomingInspectionController;
use MesCore\Auth\Middleware\UserAuth;

// 품질 ( config('mes.modules') 의 Quality ) 엔드포인트
//   입고검사는 입고관리에서도 같은 창을 연다 → 메뉴 권한은 컨트롤러가 검사 ( 입고검사 · 입고관리 어느 쪽이든 )

Route::prefix('quality')->name('quality.')->middleware([UserAuth::class])->group(function() {

    Route::prefix('incoming')->name('incoming.')->group(function() {
        Route::get('/list', [IncomingInspectionController::class, 'list'])->name('list');
        Route::get('/show/{lineIdx}', [IncomingInspectionController::class, 'show'])->whereNumber('lineIdx')->name('show');
        Route::post('/complete/{lineIdx}', [IncomingInspectionController::class, 'complete'])->whereNumber('lineIdx')->name('complete');
        Route::post('/cancel/{lineIdx}', [IncomingInspectionController::class, 'cancel'])->whereNumber('lineIdx')->name('cancel');
    });
});

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Label\Controllers\LabelController;
use MesCore\Auth\Middleware\UserAuth;

// 바코드 라벨 ( config('mes.modules') 의 Label · feature.barcode ) — 1D 바코드( Code128 )만
//   권한은 컨트롤러가 본다 : 발행 · 인쇄 = 그 대상을 여는 화면의 쓰기 권한 ( LOT = 입고관리 · 재고관리 · 제품재고 / 출하 줄 = 출하관리 )
//   발행이력 = Label/LabelHistory ( 조회 can_read · 재인쇄 can_write · 폐기 can_delete )

Route::prefix('label')->name('label.')->middleware([UserAuth::class])->group(function() {
    Route::get('/access', [LabelController::class, 'access'])->name('access');
    Route::post('/draft', [LabelController::class, 'draft'])->name('draft');
    Route::post('/issue', [LabelController::class, 'issue'])->name('issue');
    Route::post('/printed', [LabelController::class, 'printed'])->name('printed');
    Route::get('/lookup', [LabelController::class, 'lookup'])->name('lookup');   // 스캔한 번호 확인 ( 폐기 라벨 알림 )

    Route::get('/history/list', [LabelController::class, 'list'])->name('history.list');
    Route::get('/history/sheets', [LabelController::class, 'sheets'])->name('history.sheets');
    Route::post('/history/scrap', [LabelController::class, 'scrap'])->name('history.scrap');
});

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Label\Controllers\LabelController;
use MesCore\Label\Controllers\LabelTemplateController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 바코드 라벨 ( config('mes.modules') 의 Label · feature.barcode ) — 1D 바코드( Code128 )만
//   권한은 컨트롤러가 본다 : 발행 · 인쇄 = 그 대상을 여는 화면의 쓰기 권한 ( LOT = 입고관리 · 재고관리 · 제품재고 / 출하 줄 = 출하관리 )
//   발행이력 = Label/LabelHistory ( 조회 · 미리보기 can_read · 재인쇄 = 인쇄 기록( printed ) 이 대상별 발행 권한 · 폐기 can_delete )

Route::prefix('label')->name('label.')->middleware([UserAuth::class])->group(function() {
    Route::get('/access', [LabelController::class, 'access'])->name('access');
    Route::post('/draft', [LabelController::class, 'draft'])->name('draft');
    Route::post('/issue', [LabelController::class, 'issue'])->name('issue');
    Route::post('/printed', [LabelController::class, 'printed'])->name('printed');
    Route::get('/lookup', [LabelController::class, 'lookup'])->name('lookup');   // 스캔한 번호 확인 ( 폐기 라벨 알림 )

    Route::get('/history/list', [LabelController::class, 'list'])->name('history.list');
    Route::get('/history/sheets', [LabelController::class, 'sheets'])->name('history.sheets');
    Route::post('/history/scrap', [LabelController::class, 'scrap'])->name('history.scrap');

    // 라벨 양식 ( 기준정보관리 > 라벨 양식 · Label/LabelTemplateManage · 11-18 )
    Route::prefix('template')->name('template.')->group(function() {
        Route::get('/list', [LabelTemplateController::class, 'list'])->middleware(MenuAuth::class . ':Label/LabelTemplateManage,can_read')->name('list');
        Route::post('/save', [LabelTemplateController::class, 'save'])->middleware(MenuAuth::class . ':Label/LabelTemplateManage,can_write')->name('save');
        Route::post('/delete', [LabelTemplateController::class, 'delete'])->middleware(MenuAuth::class . ':Label/LabelTemplateManage,can_delete')->name('del');
    });
});

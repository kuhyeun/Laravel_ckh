<?php

use Illuminate\Support\Facades\Route;
use MesCore\Production\Controllers\MaterialIssueController;
use MesCore\Production\Controllers\OpHistoryController;
use MesCore\Production\Controllers\OpResultController;
use MesCore\Production\Controllers\WorkOrderController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 생산 ( config('mes.modules') 의 Production ) 엔드포인트 — docs/screens/50-생산.md
//   권한 : 조회 can_read / 등록 · 수정 can_write / 취소 can_delete

Route::prefix('production')->name('production.')->middleware([UserAuth::class])->group(function() {

    // 작업지시 ( Production/WorkOrderManage ) — 목록 · 조회 · 저장 · 취소는 지시서 단위, 줄 취소는 품목 줄 하나
    Route::prefix('work-order')->name('workOrder.')->group(function() {
        $read   = MenuAuth::class . ':Production/WorkOrderManage,can_read';
        $write  = MenuAuth::class . ':Production/WorkOrderManage,can_write';
        $delete = MenuAuth::class . ':Production/WorkOrderManage,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [WorkOrderController::class, 'list'])->name('list');
            Route::get('/show/{sheetIdx}', [WorkOrderController::class, 'show'])->whereNumber('sheetIdx')->name('show');
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
            Route::post('/cancelLine', [WorkOrderController::class, 'cancelLine'])->name('cancelLine');
        });
    });

    // 공정실적 ( Production/OpResult — 메뉴 생산관리 > 공정실적관리 4002 ) — 실적 입력 · 완료 · 출고 can_write / 실적 취소 · 완료 취소 · 출고 취소 can_delete
    Route::prefix('op-result')->name('opResult.')->group(function() {
        $read   = MenuAuth::class . ':Production/OpResult,can_read';
        $write  = MenuAuth::class . ':Production/OpResult,can_write';
        $delete = MenuAuth::class . ':Production/OpResult,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [OpResultController::class, 'list'])->name('list');
            Route::get('/show/{woIdx}', [OpResultController::class, 'show'])->whereNumber('woIdx')->name('show');
            Route::get('/formData', [OpResultController::class, 'formData'])->name('formData');
            // 원자재출고 공정의 [출고] — 자재출고와 같은 처리를 공정실적 권한으로
            Route::get('/issuePlan/{woIdx}', [MaterialIssueController::class, 'plan'])->whereNumber('woIdx')->name('issuePlan');
            Route::get('/issueHistory/{woIdx}', [MaterialIssueController::class, 'history'])->whereNumber('woIdx')->name('issueHistory');
            Route::get('/returnPlan/{woIdx}', [MaterialIssueController::class, 'returnPlan'])->whereNumber('woIdx')->name('returnPlan');
        });
        Route::middleware($write)->group(function() {
            Route::post('/addRun', [OpResultController::class, 'addRun'])->name('addRun');
            Route::post('/complete', [OpResultController::class, 'complete'])->name('complete');
            Route::post('/issueSave', [MaterialIssueController::class, 'save'])->name('issueSave');
            Route::post('/returnSave', [MaterialIssueController::class, 'returnSave'])->name('returnSave');
        });
        Route::middleware($delete)->group(function() {
            Route::post('/issueCancel', [MaterialIssueController::class, 'cancel'])->name('issueCancel');
            Route::post('/returnCancel', [MaterialIssueController::class, 'returnCancel'])->name('returnCancel');
            Route::post('/cancelRun', [OpResultController::class, 'cancelRun'])->name('cancelRun');
            Route::post('/cancelComplete', [OpResultController::class, 'cancelComplete'])->name('cancelComplete');
            Route::post('/cancelLineDone', [OpResultController::class, 'cancelLineDone'])->name('cancelLineDone');
        });
    });

    // 생산실적현황 ( Production/OpHistory — 메뉴 생산관리 > 생산실적현황 4003 ) — 조회만
    Route::prefix('op-history')->name('opHistory.')->middleware(MenuAuth::class . ':Production/OpHistory,can_read')->group(function() {
        Route::get('/list', [OpHistoryController::class, 'list'])->name('list');
        Route::get('/summary', [OpHistoryController::class, 'summary'])->name('summary');
        Route::get('/detail/{woIdx}', [OpHistoryController::class, 'detail'])->whereNumber('woIdx')->name('detail');
        Route::get('/formData', [OpHistoryController::class, 'formData'])->name('formData');
    });

    // 자재출고 ( Production/MaterialIssue — 메뉴는 자재관리 > 출고관리 3004 ) — 출고 · 출고 취소 · 반납 · 반납 취소 모두 can_write
    Route::prefix('material-issue')->name('materialIssue.')->group(function() {
        Route::middleware(MenuAuth::class . ':Production/MaterialIssue,can_read')->group(function() {
            Route::get('/list', [MaterialIssueController::class, 'list'])->name('list');
            Route::get('/plan/{woIdx}', [MaterialIssueController::class, 'plan'])->whereNumber('woIdx')->name('plan');
            Route::get('/history/{woIdx}', [MaterialIssueController::class, 'history'])->whereNumber('woIdx')->name('history');
            Route::get('/returnPlan/{woIdx}', [MaterialIssueController::class, 'returnPlan'])->whereNumber('woIdx')->name('returnPlan');
        });
        Route::middleware(MenuAuth::class . ':Production/MaterialIssue,can_write')->group(function() {
            Route::post('/save', [MaterialIssueController::class, 'save'])->name('save');
            Route::post('/cancel', [MaterialIssueController::class, 'cancel'])->name('cancel');
            Route::post('/returnSave', [MaterialIssueController::class, 'returnSave'])->name('returnSave');
            Route::post('/returnCancel', [MaterialIssueController::class, 'returnCancel'])->name('returnCancel');
        });
    });
});

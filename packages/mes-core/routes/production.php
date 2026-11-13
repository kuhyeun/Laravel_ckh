<?php

use Illuminate\Support\Facades\Route;
use MesCore\Production\Controllers\MaterialIssueController;
use MesCore\Production\Controllers\OpHistoryController;
use MesCore\Production\Controllers\WipController;
use MesCore\Production\Controllers\SubconController;
use MesCore\Production\Controllers\OpResultController;
use MesCore\Production\Controllers\ProductionPlanController;
use MesCore\Production\Controllers\ProductionScheduleController;
use MesCore\Production\Controllers\WorkOrderController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 생산 ( config('mes.modules') 의 Production ) 엔드포인트 — docs/screens/50-생산.md
//   권한 : 조회 can_read / 등록 · 수정 can_write / 취소 can_delete

Route::prefix('production')->name('production.')->middleware([UserAuth::class])->group(function() {

    // 생산일정 ( Production/ProductionSchedule ) — 조회 전용 간트
    Route::middleware(MenuAuth::class . ':Production/ProductionSchedule,can_read')
        ->get('/schedule/list', [ProductionScheduleController::class, 'list'])->name('schedule.list');

    // 생산계획 ( Production/ProductionPlan ) — 월 달력 · 목록 · 끌어 옮기기 · [공정] · [자재]( 수주 줄 것을 같이 고침 ) — docs/생산계획-설계.md
    Route::prefix('plan')->name('plan.')->group(function() {
        $read   = MenuAuth::class . ':Production/ProductionPlan,can_read';
        $write  = MenuAuth::class . ':Production/ProductionPlan,can_write';
        $delete = MenuAuth::class . ':Production/ProductionPlan,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [ProductionPlanController::class, 'list'])->name('list');
            Route::get('/show/{planIdx}', [ProductionPlanController::class, 'show'])->whereNumber('planIdx')->name('show');
            Route::get('/orderLines', [ProductionPlanController::class, 'orderLines'])->name('orderLines');
            Route::get('/lineSetup/{lineIdx}', [ProductionPlanController::class, 'lineSetup'])->whereNumber('lineIdx')->name('lineSetup');
        });
        Route::middleware($write)->group(function() {
            Route::post('/save', [ProductionPlanController::class, 'save'])->name('save');
            Route::post('/move', [ProductionPlanController::class, 'move'])->name('move');
            Route::post('/saveLineSetup', [ProductionPlanController::class, 'saveLineSetup'])->name('saveLineSetup');
        });
        Route::middleware($delete)->group(function() {
            Route::post('/delete', [ProductionPlanController::class, 'delete'])->name('del');
        });
    });

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
            Route::get('/inputCandidates', [OpResultController::class, 'inputCandidates'])->name('inputCandidates');
            // 원자재출고 공정의 [출고] — 자재출고와 같은 처리를 공정실적 권한으로
            Route::get('/issuePlan/{woIdx}', [MaterialIssueController::class, 'plan'])->whereNumber('woIdx')->name('issuePlan');
            Route::get('/issueHistory/{woIdx}', [MaterialIssueController::class, 'history'])->whereNumber('woIdx')->name('issueHistory');
            Route::get('/returnPlan/{woIdx}', [MaterialIssueController::class, 'returnPlan'])->whereNumber('woIdx')->name('returnPlan');
        });
        Route::middleware($write)->group(function() {
            Route::post('/addRun', [OpResultController::class, 'addRun'])->name('addRun');
            // 투입 LOT ( 공정 LOT 추적 — 실적LOT-설계 §5 ) : 나중에 더하기 · 해제
            Route::post('/addInputs', [OpResultController::class, 'addInputs'])->name('addInputs');
            Route::post('/cancelInput', [OpResultController::class, 'cancelInput'])->name('cancelInput');
            Route::post('/complete', [OpResultController::class, 'complete'])->name('complete');
            Route::post('/completeAll', [OpResultController::class, 'completeAll'])->name('completeAll');   // 모든 공정 완료 ( 11-12 )
            Route::post('/issueSave', [MaterialIssueController::class, 'save'])->name('issueSave');
            Route::post('/returnSave', [MaterialIssueController::class, 'returnSave'])->name('returnSave');
            Route::post('/leftoverSave', [MaterialIssueController::class, 'leftoverSave'])->name('leftoverSave');   // 잔재 입고 ( 11-12 )
        });
        Route::middleware($delete)->group(function() {
            Route::post('/issueCancel', [MaterialIssueController::class, 'cancel'])->name('issueCancel');
            Route::post('/returnCancel', [MaterialIssueController::class, 'returnCancel'])->name('returnCancel');
            Route::post('/leftoverCancel', [MaterialIssueController::class, 'leftoverCancel'])->name('leftoverCancel');
            Route::post('/cancelRun', [OpResultController::class, 'cancelRun'])->name('cancelRun');
            Route::post('/cancelComplete', [OpResultController::class, 'cancelComplete'])->name('cancelComplete');
            Route::post('/cancelLineDone', [OpResultController::class, 'cancelLineDone'])->name('cancelLineDone');
        });
    });

    // 외주관리 ( 4005 — 외주 출고 · 입고 · 품질검사 · 이력 ) — 화면마다 그 메뉴 권한 ( 같은 기능을 화면별 이름으로 )
    Route::prefix('subcon')->name('subcon.')->group(function() {
        $auth = fn (string $page, string $act) => MenuAuth::class . ":Production/{$page},{$act}";

        Route::prefix('issue')->name('issue.')->group(function() use ($auth) {
            Route::middleware($auth('SubconIssue', 'can_read'))->group(function() {
                Route::get('/formData', [SubconController::class, 'formData'])->name('formData');
                Route::get('/candidates', [SubconController::class, 'candidates'])->name('candidates');
                Route::get('/inputCandidates', [SubconController::class, 'inputCandidates'])->name('inputCandidates');   // 내보낼 LOT ( 공정 LOT 추적 )
                Route::get('/issues', [SubconController::class, 'issues'])->name('issues');
            });
            Route::post('/save', [SubconController::class, 'issue'])->middleware($auth('SubconIssue', 'can_write'))->name('save');
            Route::post('/cancel', [SubconController::class, 'cancelIssue'])->middleware($auth('SubconIssue', 'can_delete'))->name('cancel');
        });

        Route::prefix('receipt')->name('receipt.')->group(function() use ($auth) {
            Route::middleware($auth('SubconReceipt', 'can_read'))->group(function() {
                Route::get('/formData', [SubconController::class, 'formData'])->name('formData');
                Route::get('/issues', [SubconController::class, 'issues'])->name('issues');
                Route::get('/receipts', [SubconController::class, 'receipts'])->name('receipts');
                Route::get('/inspection/{receiptIdx}', [SubconController::class, 'inspection'])->whereNumber('receiptIdx')->name('inspection');
            });
            Route::post('/save', [SubconController::class, 'receive'])->middleware($auth('SubconReceipt', 'can_write'))->name('save');
            Route::post('/cancel', [SubconController::class, 'cancelReceipt'])->middleware($auth('SubconReceipt', 'can_delete'))->name('cancel');
        });

        Route::prefix('inspection')->name('inspection.')->group(function() use ($auth) {
            Route::middleware($auth('SubconInspection', 'can_read'))->group(function() {
                Route::get('/formData', [SubconController::class, 'formData'])->name('formData');
                Route::get('/receipts', [SubconController::class, 'receipts'])->name('receipts');
                Route::get('/show/{receiptIdx}', [SubconController::class, 'inspection'])->whereNumber('receiptIdx')->name('show');
            });
            Route::post('/save/{receiptIdx}', [SubconController::class, 'inspect'])->whereNumber('receiptIdx')->middleware($auth('SubconInspection', 'can_write'))->name('save');
        });

        Route::prefix('history')->name('history.')->middleware($auth('SubconHistory', 'can_read'))->group(function() {
            Route::get('/formData', [SubconController::class, 'formData'])->name('formData');
            Route::get('/list', [SubconController::class, 'history'])->name('list');
        });
    });

    // 재공관리 ( Production/WipManage — 메뉴 자재관리 > 재공관리 3010 ) — 조회만 · 공정 사이 반제품 ( WipService )
    Route::prefix('wip')->name('wip.')->middleware(MenuAuth::class . ':Production/WipManage,can_read')->group(function() {
        Route::get('/history', [WipController::class, 'history'])->name('history');
        Route::get('/formData', [WipController::class, 'formData'])->name('formData');
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
            Route::post('/leftoverSave', [MaterialIssueController::class, 'leftoverSave'])->name('leftoverSave');       // 잔재 입고 ( 11-12 )
            Route::post('/leftoverCancel', [MaterialIssueController::class, 'leftoverCancel'])->name('leftoverCancel');
        });
    });
});

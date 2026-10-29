<?php

use Illuminate\Support\Facades\Route;
use MesCore\Shipment\Controllers\ShipmentController;
use MesCore\Shipment\Controllers\ShipmentHistoryController;
use MesCore\Shipment\Controllers\InvoiceController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 출하 ( config('mes.modules') 의 Shipment ) 엔드포인트 — docs/screens/60-출하.md
//   권한 : 조회 can_read / 출하대기 저장 · 결재 요청 · 출하처리 can_write / 승인 · 반려 · 취소 can_delete ( 승인 = 관리자급 — §6-4 )

Route::prefix('shipment')->name('shipment.')->middleware([UserAuth::class])->group(function() {

    // 출하관리 ( Shipment/ShipmentManage — 메뉴 출하관리 > 출하관리 7001 )
    Route::prefix('manage')->name('manage.')->group(function() {
        $read   = MenuAuth::class . ':Shipment/ShipmentManage,can_read';
        $write  = MenuAuth::class . ':Shipment/ShipmentManage,can_write';
        $delete = MenuAuth::class . ':Shipment/ShipmentManage,can_delete';

        Route::middleware($read)->group(function() {
            Route::get('/list', [ShipmentController::class, 'list'])->name('list');
            Route::get('/show/{shipmentIdx}', [ShipmentController::class, 'show'])->whereNumber('shipmentIdx')->name('show');
            Route::get('/formData', [ShipmentController::class, 'formData'])->name('formData');
            Route::get('/soLines', [ShipmentController::class, 'soLines'])->name('soLines');
            Route::get('/candidates', [ShipmentController::class, 'candidates'])->name('candidates');
        });
        Route::middleware($write)->group(function() {
            Route::post('/save', [ShipmentController::class, 'save'])->name('save');
            Route::post('/requestApproval', [ShipmentController::class, 'requestApproval'])->name('requestApproval');
            Route::post('/process', [ShipmentController::class, 'process'])->name('process');
        });
        Route::middleware($delete)->group(function() {
            Route::post('/approve', [ShipmentController::class, 'approve'])->name('approve');
            Route::post('/reject', [ShipmentController::class, 'reject'])->name('reject');
            Route::post('/cancel', [ShipmentController::class, 'cancel'])->name('cancel');
        });
    });

    // 출하이력관리 ( Shipment/ShipmentHistory — 메뉴 출하관리 > 출하이력관리 7002 ) — 조회만
    //   행 클릭의 출하 창( 읽기 )은 7002 권한으로 ( show · form · candidates 는 ShipmentController 그대로 )
    Route::prefix('history')->name('history.')->middleware(MenuAuth::class . ':Shipment/ShipmentHistory,can_read')->group(function() {
        Route::get('/list', [ShipmentHistoryController::class, 'list'])->name('list');
        Route::get('/formData', [ShipmentHistoryController::class, 'formData'])->name('formData');
        Route::get('/show/{shipmentIdx}', [ShipmentController::class, 'show'])->whereNumber('shipmentIdx')->name('show');
        Route::get('/form', [ShipmentController::class, 'formData'])->name('form');
        Route::get('/candidates', [ShipmentController::class, 'candidates'])->name('candidates');
    });

    // 거래명세서 ( Shipment/InvoiceManage — 메뉴 출하관리 > 거래명세서 7003 ) — 조회 · 인쇄
    Route::prefix('invoice')->name('invoice.')->middleware(MenuAuth::class . ':Shipment/InvoiceManage,can_read')->group(function() {
        Route::get('/list', [InvoiceController::class, 'list'])->name('list');
        Route::get('/formData', [InvoiceController::class, 'formData'])->name('formData');
        Route::get('/sheets', [InvoiceController::class, 'sheets'])->name('sheets');
    });
});

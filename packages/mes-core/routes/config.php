<?php

use Illuminate\Support\Facades\Route;
use MesCore\Config\Controllers\ItemController;
use MesCore\Config\Controllers\PartnerController;
use MesCore\Config\Controllers\ProcessController;
use MesCore\Config\Controllers\EquipmentController;
use MesCore\Config\Controllers\WarehouseController;
use MesCore\Config\Controllers\RoutingController;
use MesCore\Config\Controllers\BomController;
use MesCore\Config\Controllers\InspectionItemController;
use MesCore\Config\Controllers\InspectionSpecController;
use MesCore\Config\Controllers\LeadTimeController;
use MesCore\Config\Controllers\LotRuleController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 기준정보관리 ( Config 모듈 ) 데이터 엔드포인트
//   페이지 렌더는 제네릭 라우터( url_path → page_component )가 처리한다.
//   권한은 각 화면( page_component )의 메뉴 권한으로 검사 → 조회 can_read / 등록·수정 can_write
//   기준정보는 물리 삭제하지 않는다 → 삭제 라우트는 미사용 처리( is_use = N ), 권한은 can_delete

Route::prefix('config')->name('config.')->middleware([UserAuth::class])->group(function() {

    // 품목관리 ( Config/ItemManage )
    Route::prefix('item')->name('item.')->group(function() {
        $itemRead   = MenuAuth::class . ':Config/ItemManage,can_read';
        $itemWrite  = MenuAuth::class . ':Config/ItemManage,can_write';
        $itemDelete = MenuAuth::class . ':Config/ItemManage,can_delete';

        Route::middleware($itemRead)->group(function() {
            Route::get('/list', [ItemController::class, 'list'])->name('list');
            Route::get('/show/{itemIdx}', [ItemController::class, 'show'])->whereNumber('itemIdx')->name('show');
            // 품목 모달용 : 라우팅 · 자재 선택지 + 주 자재( BOM ) 현황
            Route::get('/formData', [ItemController::class, 'formData'])->name('formData');
        });

        Route::middleware($itemWrite)->group(function() {
            Route::post('/save', [ItemController::class, 'save'])->name('save');
        });

        Route::middleware($itemDelete)->group(function() {
            Route::post('/delete', [ItemController::class, 'deleteMany'])->name('del');
        });
    });

    // 거래처관리 ( Config/PartnerManage )
    Route::prefix('partner')->name('partner.')->group(function() {
        $partnerRead   = MenuAuth::class . ':Config/PartnerManage,can_read';
        $partnerWrite  = MenuAuth::class . ':Config/PartnerManage,can_write';
        $partnerDelete = MenuAuth::class . ':Config/PartnerManage,can_delete';

        Route::middleware($partnerRead)->group(function() {
            Route::get('/list', [PartnerController::class, 'list'])->name('list');
            Route::get('/show/{partnerIdx}', [PartnerController::class, 'show'])->whereNumber('partnerIdx')->name('show');
        });

        Route::middleware($partnerWrite)->group(function() {
            Route::post('/save', [PartnerController::class, 'save'])->name('save');
        });

        Route::middleware($partnerDelete)->group(function() {
            Route::post('/delete', [PartnerController::class, 'deleteMany'])->name('del');
        });
    });

    // 공정관리 ( Config/ProcessManage )
    Route::prefix('process')->name('process.')->group(function() {
        $processRead   = MenuAuth::class . ':Config/ProcessManage,can_read';
        $processWrite  = MenuAuth::class . ':Config/ProcessManage,can_write';
        $processDelete = MenuAuth::class . ':Config/ProcessManage,can_delete';

        Route::middleware($processRead)->group(function() {
            Route::get('/list', [ProcessController::class, 'list'])->name('list');
            Route::get('/show/{processIdx}', [ProcessController::class, 'show'])->whereNumber('processIdx')->name('show');
            // 공정 화면의 설비 선택지 ( 설비관리 권한 없이도 조회 가능 )
            Route::get('/equipments', [ProcessController::class, 'equipmentOptions'])->name('equipments');
        });

        Route::middleware($processWrite)->group(function() {
            Route::post('/save', [ProcessController::class, 'save'])->name('save');
        });

        Route::middleware($processDelete)->group(function() {
            Route::post('/delete', [ProcessController::class, 'deleteMany'])->name('del');
        });
    });

    // BOM관리 ( Config/BomManage )
    Route::prefix('bom')->name('bom.')->group(function() {
        $bomRead  = MenuAuth::class . ':Config/BomManage,can_read';
        $bomWrite = MenuAuth::class . ':Config/BomManage,can_write';

        Route::middleware($bomRead)->group(function() {
            Route::get('/items', [BomController::class, 'items'])->name('items');
            Route::get('/versions/{itemIdx}', [BomController::class, 'versions'])->whereNumber('itemIdx')->name('versions');
            Route::get('/show/{bomIdx}', [BomController::class, 'show'])->whereNumber('bomIdx')->name('show');
            Route::get('/routingLines/{itemIdx}', [BomController::class, 'routingLines'])->whereNumber('itemIdx')->name('routingLines');
        });

        Route::middleware($bomWrite)->group(function() {
            Route::post('/save', [BomController::class, 'save'])->name('save');
            Route::post('/activate', [BomController::class, 'activate'])->name('activate');
            Route::post('/approve', [BomController::class, 'approve'])->name('approve');
            Route::post('/copyVersion', [BomController::class, 'copyVersion'])->name('copyVersion');
        });
    });

    // 공정라우팅관리 ( Config/RoutingManage )
    Route::prefix('routing')->name('routing.')->group(function() {
        $routingRead   = MenuAuth::class . ':Config/RoutingManage,can_read';
        $routingWrite  = MenuAuth::class . ':Config/RoutingManage,can_write';
        $routingDelete = MenuAuth::class . ':Config/RoutingManage,can_delete';

        Route::middleware($routingRead)->group(function() {
            Route::get('/list', [RoutingController::class, 'list'])->name('list');
            Route::get('/show/{routingIdx}', [RoutingController::class, 'show'])->whereNumber('routingIdx')->name('show');
            // 라우팅 화면의 공정 선택지 ( 공정관리 권한 없이도 조회 가능 )
            Route::get('/processes', [RoutingController::class, 'processOptions'])->name('processes');
        });

        Route::middleware($routingWrite)->group(function() {
            Route::post('/save', [RoutingController::class, 'save'])->name('save');
        });

        Route::middleware($routingDelete)->group(function() {
            Route::post('/delete', [RoutingController::class, 'deleteMany'])->name('del');
        });
    });

    // 창고관리 ( Config/WarehouseManage )
    Route::prefix('warehouse')->name('warehouse.')->group(function() {
        $warehouseRead   = MenuAuth::class . ':Config/WarehouseManage,can_read';
        $warehouseWrite  = MenuAuth::class . ':Config/WarehouseManage,can_write';
        $warehouseDelete = MenuAuth::class . ':Config/WarehouseManage,can_delete';

        Route::middleware($warehouseRead)->group(function() {
            Route::get('/list', [WarehouseController::class, 'list'])->name('list');
        });

        Route::middleware($warehouseWrite)->group(function() {
            Route::post('/save', [WarehouseController::class, 'save'])->name('save');
        });

        Route::middleware($warehouseDelete)->group(function() {
            Route::post('/delete', [WarehouseController::class, 'deleteMany'])->name('del');
        });
    });

    // 설비관리 ( Config/EquipmentManage )
    Route::prefix('equipment')->name('equipment.')->group(function() {
        $equipmentRead   = MenuAuth::class . ':Config/EquipmentManage,can_read';
        $equipmentWrite  = MenuAuth::class . ':Config/EquipmentManage,can_write';
        $equipmentDelete = MenuAuth::class . ':Config/EquipmentManage,can_delete';

        Route::middleware($equipmentRead)->group(function() {
            Route::get('/list', [EquipmentController::class, 'list'])->name('list');
        });

        Route::middleware($equipmentWrite)->group(function() {
            Route::post('/save', [EquipmentController::class, 'save'])->name('save');
        });

        Route::middleware($equipmentDelete)->group(function() {
            Route::post('/delete', [EquipmentController::class, 'deleteMany'])->name('del');
        });
    });

    // 검사항목관리 ( Config/InspectionItemManage )
    Route::prefix('inspection-item')->name('inspection_item.')->group(function() {
        $inspectionItemRead   = MenuAuth::class . ':Config/InspectionItemManage,can_read';
        $inspectionItemWrite  = MenuAuth::class . ':Config/InspectionItemManage,can_write';
        $inspectionItemDelete = MenuAuth::class . ':Config/InspectionItemManage,can_delete';

        Route::middleware($inspectionItemRead)->group(function() {
            Route::get('/list', [InspectionItemController::class, 'list'])->name('list');
        });

        Route::middleware($inspectionItemWrite)->group(function() {
            Route::post('/save', [InspectionItemController::class, 'save'])->name('save');
        });

        Route::middleware($inspectionItemDelete)->group(function() {
            Route::post('/delete', [InspectionItemController::class, 'deleteMany'])->name('del');
        });
    });

    // 검사기준서관리 ( Config/InspectionSpecManage )
    Route::prefix('inspection-spec')->name('inspection_spec.')->group(function() {
        $inspectionSpecRead   = MenuAuth::class . ':Config/InspectionSpecManage,can_read';
        $inspectionSpecWrite  = MenuAuth::class . ':Config/InspectionSpecManage,can_write';
        $inspectionSpecDelete = MenuAuth::class . ':Config/InspectionSpecManage,can_delete';

        Route::middleware($inspectionSpecRead)->group(function() {
            Route::get('/list', [InspectionSpecController::class, 'list'])->name('list');
            Route::get('/show/{specIdx}', [InspectionSpecController::class, 'show'])->whereNumber('specIdx')->name('show');
            // 기준서 화면용 : 품목 · 검사항목 마스터 선택지
            Route::get('/options', [InspectionSpecController::class, 'options'])->name('options');
        });

        Route::middleware($inspectionSpecWrite)->group(function() {
            Route::post('/save', [InspectionSpecController::class, 'save'])->name('save');
        });

        Route::middleware($inspectionSpecDelete)->group(function() {
            Route::post('/delete', [InspectionSpecController::class, 'deleteMany'])->name('del');
        });
    });

    // 제조리드타임관리 ( Config/LeadTimeManage )
    Route::prefix('lead-time')->name('lead_time.')->group(function() {
        $leadTimeRead   = MenuAuth::class . ':Config/LeadTimeManage,can_read';
        $leadTimeWrite  = MenuAuth::class . ':Config/LeadTimeManage,can_write';
        $leadTimeDelete = MenuAuth::class . ':Config/LeadTimeManage,can_delete';

        Route::middleware($leadTimeRead)->group(function() {
            Route::get('/list', [LeadTimeController::class, 'list'])->name('list');
            // 등록 모달용 품목 선택지
            Route::get('/items', [LeadTimeController::class, 'itemOptions'])->name('items');
        });

        Route::middleware($leadTimeWrite)->group(function() {
            Route::post('/save', [LeadTimeController::class, 'save'])->name('save');
        });

        Route::middleware($leadTimeDelete)->group(function() {
            Route::post('/delete', [LeadTimeController::class, 'deleteMany'])->name('del');
        });
    });

    // LOT규칙관리 ( Config/LotRuleManage )
    Route::prefix('lot-rule')->name('lot_rule.')->group(function() {
        $lotRuleRead   = MenuAuth::class . ':Config/LotRuleManage,can_read';
        $lotRuleWrite  = MenuAuth::class . ':Config/LotRuleManage,can_write';
        $lotRuleDelete = MenuAuth::class . ':Config/LotRuleManage,can_delete';

        Route::middleware($lotRuleRead)->group(function() {
            Route::get('/list', [LotRuleController::class, 'list'])->name('list');
        });

        Route::middleware($lotRuleWrite)->group(function() {
            Route::post('/save', [LotRuleController::class, 'save'])->name('save');
        });

        Route::middleware($lotRuleDelete)->group(function() {
            Route::post('/delete', [LotRuleController::class, 'deleteMany'])->name('del');
        });
    });
});

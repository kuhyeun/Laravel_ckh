<?php

use Illuminate\Support\Facades\Route;
use MesCore\Config\Controllers\ItemController;
use MesCore\Config\Controllers\PartnerController;
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
});

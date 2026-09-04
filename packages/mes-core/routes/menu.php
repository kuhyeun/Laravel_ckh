<?php

use Illuminate\Support\Facades\Route;
use MesCore\Menu\Controllers\MenuController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\AdminAuth;
use MesCore\Auth\Middleware\MenuAuth;

// '메뉴관리' 페이지( page_component: Basic/MenuManage ) 권한 기준으로 이 화면의 동작을 검사한다.
//   등록·수정 = can_write / 삭제 = can_delete
//   MenuAuth 가 page_component 로 menu_code 를 조회 → menu_code 가 바뀌어도 여기 수정 불필요

Route::prefix('menu')->name('menu.')->group(function() {

    Route::middleware([AdminAuth::class])->group(function() {
        // 조회는 AdminAuth 로 충분 (읽기)
        Route::get('/list', [MenuController::class, 'list'])->name('list');

        Route::post('/insertAdminMenu', [MenuController::class, 'insertAdminMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_write')->name('ins.admin');
        Route::post('/updateAdminMenu', [MenuController::class, 'updateAdminMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_write')->name('upt.admin');
        Route::post('/deleteAdminMenu', [MenuController::class, 'deleteAdminMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_delete')->name('del.admin');

        // 레벨별 권한 저장도 "수정" 동작이므로 can_write
        Route::post('/saveMenuOptions', [MenuController::class, 'saveMenuOptions'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_write')->name('opt.save');
    });

    Route::middleware([UserAuth::class])->group(function() {
        Route::post('/insertUserMenu', [MenuController::class, 'insertUserMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_write')->name('ins.user');
        Route::post('/updateUserMenu', [MenuController::class, 'updateUserMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_write')->name('upt.user');
        Route::post('/deleteUserMenu', [MenuController::class, 'deleteUserMenu'])
            ->middleware(MenuAuth::class . ':Basic/MenuManage,can_delete')->name('del.user');
    });
});

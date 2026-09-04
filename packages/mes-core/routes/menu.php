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
    $menuWrite  = MenuAuth::class . ':Basic/MenuManage,can_write';
    $menuDelete = MenuAuth::class . ':Basic/MenuManage,can_delete';

    Route::middleware([AdminAuth::class])->group(function() use ($menuWrite, $menuDelete) {
        // 조회는 AdminAuth 로 충분 (읽기)
        Route::get('/list', [MenuController::class, 'list'])->name('list');

        // 등록·수정·권한저장 = can_write
        Route::middleware($menuWrite)->group(function() {
            Route::post('/insertAdminMenu', [MenuController::class, 'insertAdminMenu'])->name('ins.admin');
            Route::post('/updateAdminMenu', [MenuController::class, 'updateAdminMenu'])->name('upt.admin');
            Route::post('/saveMenuOptions', [MenuController::class, 'saveMenuOptions'])->name('opt.save');
        });

        // 삭제 = can_delete
        Route::middleware($menuDelete)->group(function() {
            Route::post('/deleteAdminMenu', [MenuController::class, 'deleteAdminMenu'])->name('del.admin');
        });
    });

    Route::middleware([UserAuth::class])->group(function() use ($menuWrite, $menuDelete) {
        // 등록·수정 = can_write
        Route::middleware($menuWrite)->group(function() {
            Route::post('/insertUserMenu', [MenuController::class, 'insertUserMenu'])->name('ins.user');
            Route::post('/updateUserMenu', [MenuController::class, 'updateUserMenu'])->name('upt.user');
        });

        // 삭제 = can_delete
        Route::middleware($menuDelete)->group(function() {
            Route::post('/deleteUserMenu', [MenuController::class, 'deleteUserMenu'])->name('del.user');
        });
    });
});

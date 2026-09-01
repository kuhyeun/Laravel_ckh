<?php

use Illuminate\Support\Facades\Route;
use MesCore\Menu\Controllers\MenuController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\AdminAuth;

Route::prefix('menu')->name('menu.')->group(function() {

    Route::middleware([AdminAuth::class])->group(function() {
        Route::get('/list', [MenuController::class, 'list'])->name('list');

        Route::post('/insertAdminMenu', [MenuController::class, 'insertAdminMenu'])->name('ins.admin');
        Route::post('/updateAdminMenu', [MenuController::class, 'updateAdminMenu'])->name('upt.admin');
        Route::post('/deleteAdminMenu', [MenuController::class, 'deleteAdminMenu'])->name('del.admin');

        // 메뉴 레벨별 권한( menu_option ) 저장 - 관리 작업이므로 AdminAuth 하위
        Route::post('/saveMenuOptions', [MenuController::class, 'saveMenuOptions'])->name('opt.save');
    });

    Route::middleware([UserAuth::class])->group(function() {
        Route::post('/insertUserMenu', [MenuController::class, 'insertUserMenu'])->name('ins.user');
        Route::post('/updateUserMenu', [MenuController::class, 'updateUserMenu'])->name('upt.user');
        Route::post('/deleteUserMenu', [MenuController::class, 'deleteUserMenu'])->name('del.user');
    });
});

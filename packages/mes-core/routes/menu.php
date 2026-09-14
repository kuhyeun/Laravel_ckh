<?php

use Illuminate\Support\Facades\Route;
use MesCore\Menu\Controllers\MenuController;
use MesCore\Auth\Middleware\AdminAuth;
use MesCore\Auth\Middleware\LevelAuth;
use MesCore\Auth\Middleware\MenuAuth;

// '메뉴관리' 페이지( page_component: Basic/MenuManage ) 권한 기준으로 이 화면의 동작을 검사한다.
//   등록·수정 = can_write / 삭제 = can_delete
//   MenuAuth 가 page_component 로 menu_code 를 조회 → menu_code 가 바뀌어도 여기 수정 불필요
//
// 메뉴 구조( 생성·수정·삭제·레벨 옵션 )는 시스템관리자( 레벨 0 ) 전용.
//   패키지형 제품이라 메뉴 구성은 제품이 정하고, 업체는 권한 부여( 사용자권한관리 )만 한다.
//   메뉴 옵션 데이터로 레벨 1 에게 메뉴관리 권한을 열어도 LevelAuth:0 에서 막힌다.

Route::prefix('menu')->name('menu.')->middleware([AdminAuth::class, LevelAuth::class . ':0'])->group(function() {
    $menuWrite  = MenuAuth::class . ':Basic/MenuManage,can_write';
    $menuDelete = MenuAuth::class . ':Basic/MenuManage,can_delete';

    Route::get('/list', [MenuController::class, 'list'])->name('list');

    // 등록·수정·권한저장 = can_write
    Route::middleware($menuWrite)->group(function() {
        Route::post('/insertAdminMenu', [MenuController::class, 'insertAdminMenu'])->name('ins.admin');
        Route::post('/updateAdminMenu', [MenuController::class, 'updateAdminMenu'])->name('upt.admin');
        Route::post('/insertUserMenu',  [MenuController::class, 'insertUserMenu'])->name('ins.user');
        Route::post('/updateUserMenu',  [MenuController::class, 'updateUserMenu'])->name('upt.user');
        Route::post('/saveMenuOptions', [MenuController::class, 'saveMenuOptions'])->name('opt.save');
    });

    // 삭제 = can_delete
    Route::middleware($menuDelete)->group(function() {
        Route::post('/deleteAdminMenu', [MenuController::class, 'deleteAdminMenu'])->name('del.admin');
        Route::post('/deleteUserMenu',  [MenuController::class, 'deleteUserMenu'])->name('del.user');
    });
});

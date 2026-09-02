<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\BasicController;
use MesCore\Basic\Controllers\SampleController;
use MesCore\Basic\Controllers\CodeController;
use MesCore\Auth\Middleware\AdminAuth;
use MesCore\Auth\Middleware\LevelAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 페이지 렌더는 제네릭 라우터( url_path 기반, CoreServiceProvider 의 Route::fallback )가 처리한다.
// 이 파일에는 데이터/도구 엔드포인트만 남긴다.

Route::middleware([AdminAuth::class])->group(function() {
    Route::prefix('basic')->name('basic.')->middleware(LevelAuth::class . ':0')->group(function() {
        // 시스템 환경설정 값 ( SystemConfig 페이지가 fetch )
        Route::get('/systemConfig/data', [BasicController::class, 'systemConfigData'])->name('conf.data');

        // 공통 코드관리 ( CodeManage 페이지 ). 조회는 레벨0, 등록·수정·삭제는 '코드관리'(0006) 권한 검사
        Route::prefix('code')->name('code.')->group(function() {
            Route::get('/groups', [CodeController::class, 'groups'])->name('groups');
            Route::get('/codes',  [CodeController::class, 'codes'])->name('codes');

            Route::post('/saveGroup',   [CodeController::class, 'saveGroup'])->middleware(MenuAuth::class . ':0006,can_write')->name('group.save');
            Route::post('/deleteGroup', [CodeController::class, 'deleteGroup'])->middleware(MenuAuth::class . ':0006,can_delete')->name('group.del');
            Route::post('/saveCode',    [CodeController::class, 'saveCode'])->middleware(MenuAuth::class . ':0006,can_write')->name('code.save');
            Route::post('/deleteCode',  [CodeController::class, 'deleteCode'])->middleware(MenuAuth::class . ':0006,can_delete')->name('code.del');
        });
    });

    // 샘플 API 테스트 엔드포인트 ( ApiSample 페이지가 호출 )
    Route::middleware(LevelAuth::class . ':0')->prefix('dev')->name('dev.')->group(function() {
        Route::get('/apiGet', [SampleController::class, 'apiGet'])->name('apiGet');
        Route::post('/apiPost', [SampleController::class, 'apiPost'])->name('apiPost');
    });
});

<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\BasicController;
use MesCore\Basic\Controllers\SampleController;
use MesCore\Auth\Middleware\AdminAuth;
use MesCore\Auth\Middleware\LevelAuth;

// 페이지 렌더는 제네릭 라우터( url_path 기반, CoreServiceProvider 의 Route::fallback )가 처리한다.
// 이 파일에는 데이터/도구 엔드포인트만 남긴다.

Route::middleware([AdminAuth::class])->group(function() {
    Route::prefix('basic')->name('basic.')->middleware(LevelAuth::class . ':0')->group(function() {
        // 시스템 환경설정 값 ( SystemConfig 페이지가 fetch )
        Route::get('/systemConfig/data', [BasicController::class, 'systemConfigData'])->name('conf.data');
    });

    // 샘플 API 테스트 엔드포인트 ( ApiSample 페이지가 호출 )
    Route::middleware(LevelAuth::class . ':0')->prefix('dev')->name('dev.')->group(function() {
        Route::get('/apiGet', [SampleController::class, 'apiGet'])->name('apiGet');
        Route::post('/apiPost', [SampleController::class, 'apiPost'])->name('apiPost');
    });
});

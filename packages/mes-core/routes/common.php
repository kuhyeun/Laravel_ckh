<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\CommonCodeController;
use MesCore\Auth\Middleware\UserAuth;

// 업무 화면 공용 조회 API ( 로그인만 필요, 메뉴 권한과 무관 )
//   특정 메뉴에 속하지 않고 여러 화면이 함께 쓰는 기준 데이터 조회만 둔다 ( 등록·수정·삭제 금지 )

Route::prefix('common')->name('common.')->middleware([UserAuth::class])->group(function() {
    Route::get('/codes', [CommonCodeController::class, 'codes'])->name('codes');
});

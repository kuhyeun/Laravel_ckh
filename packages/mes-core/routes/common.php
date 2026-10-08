<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\CommonCodeController;
use MesCore\Basic\Controllers\ExcelLayoutController;
use MesCore\Basic\Controllers\SearchDefaultsController;
use MesCore\Auth\Middleware\UserAuth;

// 업무 화면 공용 조회 API ( 로그인만 필요, 메뉴 권한과 무관 )
//   특정 메뉴에 속하지 않고 여러 화면이 함께 쓰는 기준 데이터 조회만 둔다 ( 등록·수정·삭제 금지 )

Route::prefix('common')->name('common.')->middleware([UserAuth::class])->group(function() {
    Route::get('/codes', [CommonCodeController::class, 'codes'])->name('codes');

    // 공통 검색바( CMP-02 ) 기간 기본값 ( 정책 ui.search_range_* — 오늘 기준 )
    Route::get('/search-defaults', [SearchDefaultsController::class, 'defaults'])->name('searchDefaults');

    // 세션 연장 : 요청 자체가 세션의 마지막 활동 시각을 갱신한다 ( 만료 전 안내 배너의 [연장] )
    Route::post('/session/keep-alive', fn () => response()->json(['message' => 'Success']))->name('session.keepAlive');

    // 엑셀 양식 ( 업체 → 공통 순서로 파일을 찾는다 )
    Route::get('/excel/layouts', [ExcelLayoutController::class, 'layouts'])->name('excel.layouts');
    Route::get('/excel/template', [ExcelLayoutController::class, 'template'])->name('excel.template');
});

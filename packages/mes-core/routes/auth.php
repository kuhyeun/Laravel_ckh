<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use MesCore\Auth\Models\User;
use MesCore\Auth\Support\LandingPage;
use MesCore\Auth\Controllers\DashboardController;
use MesCore\Auth\Controllers\UserController;
use MesCore\Auth\Middleware\GuestAuth;
use MesCore\Auth\Middleware\UserAuth;

// 사이트 주소( / ) — 로그인했으면 시작 메뉴( 시스템 설정 Common.landing_page · 권한 없으면 대시보드 ) / 아니면 로그인 화면
Route::get('/', function () {
    $user = Auth::user();
    return $user instanceof User ? redirect( LandingPage::for( $user ) ) : redirect()->route( 'user.login' );
});

Route::prefix('user')->name('user.')->group(function() {
    Route::redirect('/', '/user/login');

    // 게스트 사용자만 접근
    Route::middleware([GuestAuth::class])->group(function() {
        Route::get('/login', [UserController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [UserController::class, 'processLogin'])->name('login.process');

        // 회원가입은 없다 ( 11-09 — 계정은 사용자관리에서 관리자가 만든다 · 열어 두면 외부인이 가입해 공통 API 를 쓸 수 있었다 )
    });

    // 로그인된 사용자 접근
    Route::middleware([UserAuth::class])->group(function() {
        Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');   // 대시보드 숫자 · 목록 · 차트 ( 권한 제한 없음 )
        Route::post('/logout', [UserController::class, 'logout'])->name('logout');   // POST ( GET 이면 링크 하나로 강제 로그아웃 )
    });
});
<?php

use Illuminate\Support\Facades\Route;
use MesCore\Auth\Controllers\UserController;
use MesCore\Auth\Middleware\GuestAuth;
use MesCore\Auth\Middleware\UserAuth;

Route::get('/', function () {
    return redirect( '/user' );
});

Route::prefix('user')->name('user.')->group(function() {
    Route::redirect('/', '/user/login');

    // 게스트 사용자만 접근
    Route::middleware([GuestAuth::class])->group(function() {
        Route::get('/login', [UserController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [UserController::class, 'processLogin'])->name('login.process');

        Route::get('/register', [UserController::class, 'showRegistrationForm'])->name('register');
        Route::post('/register', [UserController::class, 'processRegistration'])->name('register.process');
    });

    // 로그인된 사용자 접근
    Route::middleware([UserAuth::class])->group(function() {
        Route::get('/dashboard', [UserController::class, 'dashboard'])->name('dashboard');
        Route::get('/logout', [UserController::class, 'logout'])->name('logout');
    });
});
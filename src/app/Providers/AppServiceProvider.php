<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
    }

    public function boot(): void {
        // 업체 전용 로직만 여기에 작성
        // Core 관련(Auth, Menu, Session 등)은 MesCore\CoreServiceProvider에서 처리
    }
}

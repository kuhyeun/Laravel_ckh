<?php

namespace MesCore;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use MesCore\Auth\Models\User;
use MesCore\Auth\Observers\UserObserver;
use MesCore\Menu\Services\MenuPermissionService;

class CoreServiceProvider extends ServiceProvider {

    public function register(): void {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mes.php', 'mes'
        );
    }

    public function boot(): void {
        // Config 퍼블리시 (업체에서 config/mes.php 커스텀 가능)
        $this->publishes([
            __DIR__ . '/../config/mes.php' => config_path('mes.php'),
        ], 'mes-core-config');

        // 마이그레이션 등록
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // 라우트 등록
        $this->loadRoutes();

        // Observer 등록
        User::observe(UserObserver::class);

        // 메뉴 권한 Gate 등록
        $this->registerMenuGates();

        // 세션 데이터 공유
        View::composer('*', function ($view) {
            if( Auth::check() ) {
                $sessionData = (object) [
                    'userId'    => Session::get('user_id'),
                    'userIdx'   => Session::get('user_idx'),
                    'userLevel' => Session::get('user_level'),
                    'userName'  => Session::get('user_name'),
                    'partCode'  => Session::get('part_code'),
                ];

                $view->with('mSession', $sessionData);
            }
        });
    }

    protected function registerMenuGates(): void {
        $service = $this->app->make(MenuPermissionService::class);

        Gate::define('menu.read', function (User $user, string $menuCode) use ($service) {
            return $service->can($user, $menuCode, 'can_read');
        });

        Gate::define('menu.write', function (User $user, string $menuCode) use ($service) {
            return $service->can($user, $menuCode, 'can_write');
        });

        Gate::define('menu.delete', function (User $user, string $menuCode) use ($service) {
            return $service->can($user, $menuCode, 'can_delete');
        });
    }

    protected function loadRoutes(): void {
        Route::middleware('web')->group(function () {
            // Core 라우트
            $this->loadRoutesFrom(__DIR__ . '/../routes/auth.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/menu.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/basic.php');

            // 모듈 라우트 자동 로딩
            foreach (config('mes.modules', []) as $module) {
                $path = base_path("app/Modules/{$module}/routes.php");
                if (file_exists($path)) {
                    $this->loadRoutesFrom($path);
                }
            }

            // 제네릭 페이지 라우트 ( 반드시 마지막 - 매칭 안 된 경로를 url_path 로 조회해 렌더 )
            Route::fallback([\MesCore\Page\Controllers\PageController::class, 'show']);
        });
    }
}

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
use MesCore\Basic\Services\PolicyService;
use MesCore\Basic\Services\ExcelLayouts;
use MesCore\Basic\Services\Import\ExcelImportService;
use MesCore\Config\Imports\ItemImport;
use MesCore\Config\Imports\PartnerImport;
use MesCore\Basic\Services\AttachmentService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Order\Models\SalesOrder;
use MesCore\Purchase\Models\PurchaseOrder;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Production\Models\WorkOrder;

class CoreServiceProvider extends ServiceProvider {

    // 모듈 코드 → Core 라우트 파일
    //   모듈 코드 = config/mes.php modules = 메뉴 module_code = src/app/Modules/{코드} ( 켜고 끄는 단위 · 메뉴 대분류 )
    //   Core 폴더( 네임스페이스 )는 기능 단위라 이름이 다를 수 있다 ( 예: Material = Purchase + Stock )
    //   의존 : Production 은 Material( 자재출고 · 재고 ) · Quality( 입고검사 )는 Material( 입고 )이 함께 켜져야 한다
    //   Product · Shipment 는 아직 Core 라우트 없음 ( 메뉴만 )
    private const MODULE_ROUTES = [
        'Order'      => ['order.php'],
        'Material'   => ['purchase.php', 'stock.php'],
        'Quality'    => ['quality.php'],
        'Production' => ['production.php'],
    ];

    public function register(): void {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mes.php', 'mes'
        );

        // 정책 서비스 : 요청 안에서 한 인스턴스 ( 정의 · 값을 한 번만 읽는다 )
        $this->app->singleton(PolicyService::class);

        // 엑셀 일괄 등록 : 엔티티별 핸들러를 엔진에 등록 ( 업체 모듈은 resolving 으로 더 붙일 수 있다 )
        $this->app->singleton(ExcelImportService::class, function ($app) {
            $service = new ExcelImportService($app->make(ExcelLayouts::class));
            $service->register(new ItemImport());
            $service->register(new PartnerImport());
            return $service;
        });

        // 첨부파일 : 붙일 수 있는 대상 → 권한을 따를 화면 · 대상 모델 ( 업체 모듈은 resolving 으로 더 붙일 수 있다 )
        $this->app->singleton(AttachmentService::class, function () {
            $service = new AttachmentService();
            $service->register('item', 'Config/ItemManage', Item::class);
            $service->register('partner', 'Config/PartnerManage', Partner::class);
            $service->register('sales_order', 'Order/SalesOrderManage', SalesOrder::class);
            $service->register('purchase_order', 'Purchase/PurchaseOrderManage', PurchaseOrder::class);
            // 입고 줄 ( 성적서 · 밀시트 ) — 검사 전에도 붙인다. 입고검사 · 입고관리 어느 쪽 권한으로도
            $service->register('receipt_line', ['Quality/IncomingInspection', 'Purchase/ReceiptManage'], StockTxnLine::class);
            $service->register('work_order', 'Production/WorkOrderManage', WorkOrder::class);
            return $service;
        });
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
            $this->loadRoutesFrom(__DIR__ . '/../routes/common.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/config.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/attachment.php');

            // 업무 모듈 ( 패키지 ) — 업체 config/mes.php 의 modules 로 켜고 끈다 ( 모듈 코드는 MODULE_ROUTES 참고 )
            foreach (self::MODULE_ROUTES as $module => $files) {
                if (in_array($module, config('mes.modules', []), true)) {
                    foreach ($files as $file) {
                        $this->loadRoutesFrom(__DIR__ . '/../routes/' . $file);
                    }
                }
            }

            // 업체 모듈 라우트 ( src/app/Modules/{모듈 코드}/routes.php ) — Core 다음이라 같은 주소면 업체가 이긴다
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

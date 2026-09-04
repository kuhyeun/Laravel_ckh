<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\SampleController;
use MesCore\Basic\Controllers\CodeController;
use MesCore\Basic\Controllers\ConfigController;
use MesCore\Basic\Controllers\MemberController;
use MesCore\Basic\Controllers\MemberAuthController;
use MesCore\Auth\Middleware\AdminAuth;
use MesCore\Auth\Middleware\LevelAuth;
use MesCore\Auth\Middleware\MenuAuth;

// 페이지 렌더는 제네릭 라우터( url_path 기반, CoreServiceProvider 의 Route::fallback )가 처리한다.
// 이 파일에는 데이터/도구 엔드포인트만 남긴다.



Route::middleware([AdminAuth::class])->group(function() {
    Route::prefix('basic')->name('basic.')->middleware(LevelAuth::class . ':1')->group(function() {

        // 시스템 설정 ( SystemConfig 페이지 ). 저장은 해당 페이지 권한(can_write) 검사
        // MenuAuth 는 page_component 로 menu_code 를 조회 → 코드가 바뀌어도 여기 안 고쳐도 됨
        Route::prefix('config')->name('config.')->group(function() {
            Route::get('/datas', [ConfigController::class, 'getData'])->name('datas');
            Route::post('/save', [ConfigController::class, 'save'])->middleware(MenuAuth::class . ':Basic/SystemConfig,can_write')->name('save');
        });
        
        // 공통 코드관리 ( CodeManage 페이지 ). 등록·수정·삭제는 해당 페이지 권한 검사
        Route::prefix('code')->name('code.')->group(function() {
            $codeWrite  = MenuAuth::class . ':Basic/CodeManage,can_write';
            $codeDelete = MenuAuth::class . ':Basic/CodeManage,can_delete';

            Route::get('/groups', [CodeController::class, 'groups'])->name('groups');
            Route::get('/codes',  [CodeController::class, 'codes'])->name('codes');

            Route::middleware($codeWrite)->group(function() {
                Route::post('/saveGroup', [CodeController::class, 'saveGroup'])->name('group.save');
                Route::post('/saveCode',  [CodeController::class, 'saveCode'])->name('code.save');
            });

            Route::middleware($codeDelete)->group(function() {
                Route::post('/deleteGroup', [CodeController::class, 'deleteGroup'])->name('group.del');
                Route::post('/deleteCode',  [CodeController::class, 'deleteCode'])->name('code.del');
            });
        });

        // 사용자관리 ( Member 페이지 ). 조회=read / 등록·수정=write / 삭제=delete 권한 검사
        Route::prefix('mem')->name('mem.')->group(function() {
            $memRead   = MenuAuth::class . ':Basic/Member,can_read';
            $memWrite  = MenuAuth::class . ':Basic/Member,can_write';
            $memDelete = MenuAuth::class . ':Basic/Member,can_delete';

            Route::middleware($memRead)->group(function() {
                Route::get('/member/list', [MemberController::class, 'list'])->name('list');
            });
            Route::middleware($memWrite)->group(function() {
                Route::post('/member/save', [MemberController::class, 'save'])->name('save');
            });
            Route::middleware($memDelete)->group(function() {
                Route::post('/member/delete', [MemberController::class, 'delete'])->name('del');
            });
        });

        // 사용자 권한 관리 ( MemberAuth 페이지 ). 조회=read / 저장=write
        Route::prefix('memauth')->name('memauth.')->group(function() {
            $authRead  = MenuAuth::class . ':Basic/MemberAuth,can_read';
            $authWrite = MenuAuth::class . ':Basic/MemberAuth,can_write';

            Route::middleware($authRead)->group(function() {
                Route::get('/users', [MemberAuthController::class, 'users'])->name('users');
                Route::get('/menus/{accountIdx}', [MemberAuthController::class, 'menus'])->name('menus');
            });
            Route::middleware($authWrite)->group(function() {
                Route::post('/save', [MemberAuthController::class, 'save'])->name('save');
            });
        });
    });

    // 샘플 API 테스트 엔드포인트 ( ApiSample 페이지가 호출 )
    Route::middleware(LevelAuth::class . ':0')->prefix('dev')->name('dev.')->group(function() {
        Route::get('/apiGet', [SampleController::class, 'apiGet'])->name('apiGet');
        Route::post('/apiPost', [SampleController::class, 'apiPost'])->name('apiPost');
    });
});

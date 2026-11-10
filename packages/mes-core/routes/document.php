<?php

use Illuminate\Support\Facades\Route;
use MesCore\Document\Controllers\DocumentController;
use MesCore\Auth\Middleware\UserAuth;
use MesCore\Auth\Middleware\MenuAuth;

// DATA 관리 ( Document 모듈 ) 데이터 엔드포인트
//   페이지 렌더는 제네릭 라우터( url_path → page_component )가 처리한다
//   문서관리 파일의 올리기 · 받기 · 삭제는 공용 첨부 API( routes/attachment.php — ref_type doc_folder )

Route::prefix('document')->name('document.')->middleware([UserAuth::class])->group(function() {

    // 문서관리 ( Document/DocumentManage ) — 폴더 만들기 · 이름 바꾸기 · 삭제 · 파일 옮기기 ( 폴더 옮기기는 없음 — 사용자 결정 11-10 )
    Route::prefix('docs')->name('docs.')->group(function() {
        $docRead   = MenuAuth::class . ':Document/DocumentManage,can_read';
        $docWrite  = MenuAuth::class . ':Document/DocumentManage,can_write';
        $docDelete = MenuAuth::class . ':Document/DocumentManage,can_delete';

        Route::middleware($docRead)->group(function() {
            Route::get('/folders', [DocumentController::class, 'folders'])->name('folders');
        });
        Route::middleware($docWrite)->group(function() {
            Route::post('/folder/save', [DocumentController::class, 'save'])->name('folder.save');
            Route::post('/files/move', [DocumentController::class, 'moveFiles'])->name('files.move');
        });
        Route::middleware($docDelete)->group(function() {
            Route::post('/folder/delete', [DocumentController::class, 'delete'])->name('folder.delete');
        });
    });
});

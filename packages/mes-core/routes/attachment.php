<?php

use Illuminate\Support\Facades\Route;
use MesCore\Basic\Controllers\AttachmentController;
use MesCore\Auth\Middleware\UserAuth;

// 첨부파일 ( CMP-03 ) — 여러 화면이 함께 쓰는 공용 API
//   메뉴 권한은 컨트롤러가 ref_type 이 가리키는 화면으로 검사한다 ( 보기 · 받기 = can_read, 올리기 · 삭제 = can_write )

Route::prefix('attachment')->name('attachment.')->middleware([UserAuth::class])->group(function() {
    Route::get('/list', [AttachmentController::class, 'list'])->name('list');
    Route::post('/upload', [AttachmentController::class, 'upload'])->name('upload');
    Route::get('/download/{attachmentIdx}', [AttachmentController::class, 'download'])->whereNumber('attachmentIdx')->name('download');
    Route::post('/delete/{attachmentIdx}', [AttachmentController::class, 'delete'])->whereNumber('attachmentIdx')->name('delete');
});

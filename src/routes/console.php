<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 설치 여부 ( 메뉴가 한 행이라도 있으면 설치됨 ) — docker-entrypoint 가 처음 설치 때만 시드하려고 쓴다
//   종료 코드 0 = 설치됨 / 1 = 처음 설치 ( 테이블이 없어도 1 )
Artisan::command('mes:installed', function () {
    try {
        $installed = DB::table('system_menu')->exists();
    } catch (\Throwable $e) {
        $installed = false;
    }

    $this->line($installed ? 'installed' : 'not installed');

    return $installed ? 0 : 1;
})->purpose('MES 초기 데이터가 들어가 있는지 확인');

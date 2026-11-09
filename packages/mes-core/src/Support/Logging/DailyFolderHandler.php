<?php

namespace MesCore\Support\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * 날짜별 폴더 로그 — storage/logs/{YYYY-MM-DD}/{name}.log ( 쓸 때마다 날짜를 다시 본다 — 자정을 넘긴 프로세스도 새 폴더로 )
 *   새 날 폴더를 처음 만들 때 보관 일수( days )가 지난 날짜 폴더를 지운다
 *   파일 · 폴더 권한은 그룹 쓰기( 0664 · 0775 ) — 웹( www-data ) · 콘솔( artisan ) 어느 쪽이 먼저 만들어도 서로 쓸 수 있게
 *   root 로 돌던 명령( 컨테이너 시작 등 )이 먼저 만들면 소유자를 logs 폴더 주인( www-data )으로 넘긴다 ( 11-09 — 웹 로그가 안 남던 것 )
 *   그래도 쓸 수 없으면 logs 바로 아래 {날짜}-{이름}.log 에 남긴다 ( 로그를 잃지 않게 )
 */
class DailyFolderHandler extends AbstractProcessingHandler {

    public function __construct(
        private string $root,
        private string $name,
        private int $days = 30,
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level, true);
        $f = new LineFormatter("[%datetime%] %level_name% %extra% %message% %context%\n", 'Y-m-d H:i:s.v', true, true);
        $f->includeStacktraces(true);
        $this->setFormatter($f);
    }

    protected function write(LogRecord $record): void {
        $day = $record->datetime->format('Y-m-d');
        $dir = rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . $day;
        if (!is_dir($dir)) {
            $old = umask(0002);
            @mkdir($dir, 0775, true);
            umask($old);
            $this->giveToOwner($dir);
            $this->prune();
        }
        $file  = $dir . DIRECTORY_SEPARATOR . $this->name . '.log';
        $fresh = !is_file($file);
        if (($fresh ? !is_writable($dir) : !is_writable($file))) {
            $file  = rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . "{$day}-{$this->name}.log";   // 날짜 폴더를 못 쓰면
            $fresh = !is_file($file);
        }
        @file_put_contents($file, (string) $record->formatted, FILE_APPEND | LOCK_EX);
        if ($fresh) {
            @chmod($file, 0664);
            $this->giveToOwner($file);
        }
    }

    // root 로 만들었으면 logs 폴더 주인( 보통 www-data )에게 넘긴다
    private function giveToOwner(string $path): void {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        $owner = @fileowner($this->root);
        $group = @filegroup($this->root);
        if ($owner !== false) @chown($path, $owner);
        if ($group !== false) @chgrp($path, $group);
    }

    // 보관 일수가 지난 날짜 폴더 지우기 ( YYYY-MM-DD 이름만 )
    private function prune(): void {
        if ($this->days <= 0) {
            return;
        }
        $limit = date('Y-m-d', strtotime("-{$this->days} days"));
        foreach (glob(rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $base = basename($dir);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) && $base < $limit) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }
}

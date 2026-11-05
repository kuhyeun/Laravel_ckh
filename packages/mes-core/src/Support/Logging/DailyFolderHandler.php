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
            $this->prune();
        }
        $file  = $dir . DIRECTORY_SEPARATOR . $this->name . '.log';
        $fresh = !is_file($file);
        @file_put_contents($file, (string) $record->formatted, FILE_APPEND | LOCK_EX);
        if ($fresh) {
            @chmod($file, 0664);
        }
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

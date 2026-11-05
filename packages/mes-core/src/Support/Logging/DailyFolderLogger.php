<?php

namespace MesCore\Support\Logging;

use Monolog\Logger;

// logging 'custom' 채널 만들기 — DailyFolderHandler 하나 ( 설정 : name · root · days · level )
class DailyFolderLogger {

    public function __invoke(array $config): Logger {
        $logger = new Logger($config['name'] ?? 'mes');
        $logger->pushHandler(new DailyFolderHandler(
            $config['root'] ?? storage_path('logs'),
            $config['name'] ?? 'app',
            (int) ($config['days'] ?? 30),
            $config['level'] ?? 'debug',
        ));
        return $logger;
    }
}

<?php

namespace Database\Seeders\Config;

use Illuminate\Database\Seeder;
use MesCore\Basic\Models\SystemConfig;

/**
 * config/system_config.php 를 읽어 DB 에 반영.
 * updateOrCreate 라 여기 정의된 키는 항상 config 값으로 강제됨 ( 재실행 안전 ).
 */
class SystemConfigSeeder extends Seeder {

    public function run(): void {
        foreach (config('system_config', []) as $groupKey => $items) {
            foreach ($items as $key => $conf) {
                $extra = [];
                if (isset($conf['name'])) {
                    $extra['system_config_name'] = $conf['name'];
                }
                if (isset($conf['remark'])) {
                    $extra['system_config_remark'] = $conf['remark'];
                }
                if (isset($conf['etc'])) {
                    // etc 는 모델에서 array 캐스팅 → 배열 그대로 넘기면 JSON 저장됨
                    $extra['system_config_etc'] = $conf['etc'];
                }

                SystemConfig::put($groupKey, $key, $conf['value'] ?? '', $extra);
            }
        }
    }
}

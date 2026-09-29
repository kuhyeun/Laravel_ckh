<?php

namespace Database\Seeders\Config;

use Illuminate\Database\Seeder;
use MesCore\Basic\Models\SystemConfig;
use MesCore\Basic\Services\PolicyService;

/**
 * config/system_config.php( 설정 · 정책 카탈로그 )를 읽어 DB(system_config) 의 "정의"를 동기화한다.
 *   - 정의( 이름 · 기본값 · 형태 · 스코프 · 권한 · 노출 … )만 갱신하고 설정값( system_config_value )은 건드리지 않는다
 *   - 새 항목은 설정값 NULL( = 기본값 )로 만든다
 *   - 재실행 안전. 배포 때마다 돌린다 ( 업체별 DB 가 되면 DB 마다 )
 */
class SystemConfigSeeder extends Seeder {

    public function run(): void {
        $sort = 0;

        foreach (config('system_config', []) as $groupKey => $items) {
            foreach ($items as $key => $conf) {
                $category = $conf['category'] ?? 'APP';
                $type     = $conf['type'] ?? 'STRING';
                $merge    = $conf['merge'] ?? [];

                $definition = [
                    'system_config_name'             => $conf['name'] ?? $key,
                    'system_config_remark'           => $conf['remark'] ?? null,
                    'system_config_etc'              => $conf['etc'] ?? $this->fieldMeta($type, $conf['options'] ?? []),
                    'system_config_category'         => $category,
                    'system_config_value_type'       => $type,
                    'system_config_default_value'    => isset($conf['value']) ? (string) $conf['value'] : null,
                    'system_config_allowed_scopes'   => $conf['scopes'] ?? 'TENANT',
                    'system_config_merge_strategy'   => $merge['strategy'] ?? 'OVERRIDE',
                    'system_config_exempt_value'     => $merge['exempt_value'] ?? null,
                    'system_config_exempt_scope'     => $merge['exempt_scope'] ?? null,
                    'system_config_priority_scope'   => $merge['priority_scope'] ?? null,
                    'system_config_depends_on_key'   => $conf['depends_on'][0] ?? null,
                    'system_config_depends_on_value' => $conf['depends_on'][1] ?? null,
                    // 기능 스위치는 항상 시스템관리자 전용 ( 정책-설정-설계 §7 )
                    'system_config_editable_by'      => $category === 'FEATURE' ? 'SYSTEM_ADMIN' : ($conf['editable_by'] ?? 'TENANT'),
                    'system_config_sort'             => ++$sort,
                    'system_config_is_visible'       => ($conf['visible'] ?? true) ? 'Y' : 'N',
                ];

                $row = SystemConfig::query()
                    ->where('system_config_group_key', $groupKey)
                    ->where('system_config_key', $key)
                    ->first();

                if ($row) {
                    $row->fill($definition)->save();
                } else {
                    SystemConfig::create($definition + [
                        'system_config_group_key' => $groupKey,
                        'system_config_key'       => $key,
                        'system_config_value'     => null,
                    ]);
                }
            }
        }

        app(PolicyService::class)->flush();
    }

    // 값 형태 → 화면 필드 메타 ( SystemConfig.vue 의 FIELD 레지스트리 )
    private function fieldMeta(string $type, array $options): array {
        $opts = collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();

        return match ($type) {
            'BOOL'  => ['type' => 'switch', 'trueValue' => 'Y', 'falseValue' => 'N'],
            'ENUM'  => ['type' => count($opts) <= 3 ? 'radio' : 'select', 'options' => $opts],
            'INT'   => ['type' => 'number'],
            default => ['type' => 'text'],
        };
    }
}

<?php

namespace MesCore\Basic\Services;

use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 정책 서비스 — docs/정책-설정-설계.md §4 · §10
 *   모든 모듈은 설정( 기능 스위치 · 업무 규칙 )을 이 서비스로만 읽는다. 키는 "group.key" ( 예: lot.shipment_lot )
 *
 *   get( key, ctx )  : 스코프 · 충돌 규칙 · 선행 조건을 반영한 값 ( BOOL → bool, INT → int|null, 나머지 → string|null )
 *   isEnabled( key ) : 기능 스위치 단축
 *   set( … )         : 검증( 권한 · 스코프 · 형태 · 후보 · 선행 조건 ) → 저장 → 이력 → 캐시 비움
 *
 *   ctx : [ 'item' => item_idx, 'partner' => partner_idx, 'process' => process_idx, 'warehouse' => warehouse_idx ]
 *   없는 키는 예외 ( 오타가 조용히 기본값으로 흘러가지 않도록 )
 *   캐시 : DB( = 업체 )별로 정의 · 값 · 덮어쓰기를 통째로 올려 두고, 바뀌면 비운다
 */
class PolicyService {

    // 덮어쓰기 탐색 순서 ( 구체적인 것부터 )
    private const ENTITY_ORDER = ['PROCESS', 'ITEM', 'PARTNER', 'WAREHOUSE'];

    private ?array $memo = null;

    // ---- 조회 ----

    public function get(string $key, array $ctx = []): mixed {
        return $this->cast($this->definition($key), $this->resolveRaw($key, $ctx));
    }

    public function isEnabled(string $featureKey): bool {
        return $this->get($featureKey) === true;
    }

    // 정의 한 건 ( 없으면 예외 )
    public function definition(string $key): array {
        $def = $this->load()['defs'][$key] ?? null;
        if (!$def) {
            throw new InvalidArgumentException("등록되지 않은 정책 키입니다 : {$key}");
        }
        return $def;
    }

    // 전체 정의 ( 설정 화면용, 정렬 순 )
    public function definitions(): array {
        return $this->load()['defs'];
    }

    // 선행 조건 충족 여부 ( 화면에서 숨길지 판단 )
    public function dependencySatisfied(string $key): bool {
        $def = $this->definition($key);
        if (!$def['depends_on_key']) {
            return true;
        }
        return $this->resolveRaw($def['depends_on_key']) === ($def['depends_on_value'] ?? 'Y');
    }

    // 스코프 · 충돌 규칙 · 선행 조건을 반영한 원시값( 문자열 )
    public function resolveRaw(string $key, array $ctx = []): ?string {
        $def = $this->definition($key);

        // 선행 조건이 맞지 않으면 이 정책은 무의미 → 기본값
        if ($def['depends_on_key'] && !$this->dependencySatisfied($key)) {
            return $def['default_value'];
        }

        $allowed   = $def['allowed_scopes'];
        $overrides = $this->load()['overrides'][$key] ?? [];
        $entity    = function (string $scope) use ($ctx, $allowed, $overrides): ?string {
            $idx = $ctx[strtolower($scope)] ?? null;
            if (!$idx || !in_array($scope, $allowed, true)) {
                return null;
            }
            return $overrides[$scope][(int) $idx] ?? null;
        };

        if ($def['merge_strategy'] === 'EXEMPT_THEN_PRIORITY') {
            // ① 면제 스코프가 면제값이면 확정 ( 품목이 무검사면 거래처를 무시 )
            if ($def['exempt_scope'] && $entity($def['exempt_scope']) === $def['exempt_value']) {
                return $def['exempt_value'];
            }
            // ② 우선 스코프에 값이 있으면 그 값 ( 거래처 우선 — 값이 있을 때만 )
            if ($def['priority_scope'] && ($v = $entity($def['priority_scope'])) !== null) {
                return $v;
            }
        }

        // 구체적인 스코프부터 → 업체 값 → 기본값
        foreach (self::ENTITY_ORDER as $scope) {
            if (($v = $entity($scope)) !== null) {
                return $v;
            }
        }

        return $def['value'] ?? $def['default_value'];
    }

    // ---- 저장 ----

    /**
     * 값 저장. $value = null 이면 되돌림 ( 업체 값 → 기본값 / 덮어쓰기 → 해제 )
     * @return bool 실제로 바뀌었는지 ( 같은 값이면 이력을 남기지 않는다 )
     * @throws PolicyException 검증 실패 ( 화면에 그대로 보여 줄 한국어 메시지 )
     */
    public function set(string $key, ?string $value, string $scopeType = 'TENANT', int $scopeIdx = 0, ?string $reason = null, ?User $by = null): bool {
        $def = $this->definition($key);
        $by ??= Auth::user();

        // 권한 : 시스템관리자 전용은 시스템 계정( 레벨 0 )만 ( 화면에서 막는 것과 별개로 서버가 거부 )
        if ($def['editable_by'] === 'SYSTEM_ADMIN' && (int) ($by?->user_level ?? 99) !== 0) {
            throw new PolicyException("[ {$def['name']} ] 은(는) 시스템 계정만 바꿀 수 있습니다.");
        }

        // 스코프
        if (!in_array($scopeType, $def['allowed_scopes'], true)) {
            throw new PolicyException("[ {$def['name']} ] 은(는) {$scopeType} 단위로 설정할 수 없습니다.");
        }
        if ($scopeType !== 'TENANT' && $scopeIdx <= 0) {
            throw new PolicyException('덮어쓸 대상이 지정되지 않았습니다.');
        }

        if ($value !== null) {
            $value = trim($value);
            $this->validateValue($def, $value);

            // 선행 기능이 꺼져 있으면 설정할 수 없음
            if ($def['depends_on_key'] && !$this->dependencySatisfied($key)) {
                $dep = $this->definition($def['depends_on_key']);
                throw new PolicyException("선행 설정 [ {$dep['name']} ] 이(가) 꺼져 있어 [ {$def['name']} ] 을(를) 설정할 수 없습니다.");
            }
        }

        [$group, $k] = $this->splitKey($key);

        $changed = DB::transaction(function () use ($def, $group, $k, $value, $scopeType, $scopeIdx, $reason, $by) {
            if ($scopeType === 'TENANT') {
                $old = $def['value'];
                // 설정 안 한 상태에서 기본값과 같은 값을 받으면 그대로 둔다 ( 기본값을 따라가는 상태 유지 )
                $new = ($old === null && $value === $def['default_value']) ? null : $value;
                if ($old === $new) {
                    return false;
                }
                SystemConfig::query()
                    ->where('system_config_group_key', $group)
                    ->where('system_config_key', $k)
                    ->update(['system_config_value' => $new, 'update_account_idx' => $by?->getAuthIdentifier(), 'update_datetime' => now()]);
            } else {
                $where = [
                    'system_config_group_key' => $group,
                    'system_config_key'       => $k,
                    'scope_type'              => $scopeType,
                    'scope_idx'               => $scopeIdx,
                ];
                $old = DB::table('system_config_override')->where($where)->value('override_value');
                $new = $value;
                if ($old === $new) {
                    return false;
                }
                if ($new === null) {
                    DB::table('system_config_override')->where($where)->delete();
                } elseif ($old === null) {
                    DB::table('system_config_override')->insert($where + [
                        'override_value' => $new, 'create_account_idx' => $by?->getAuthIdentifier() ?? 0, 'create_datetime' => now(),
                    ]);
                } else {
                    DB::table('system_config_override')->where($where)->update([
                        'override_value' => $new, 'update_account_idx' => $by?->getAuthIdentifier(), 'update_datetime' => now(),
                    ]);
                }
            }

            DB::table('system_config_history')->insert([
                'system_config_group_key' => $group,
                'system_config_key'       => $k,
                'scope_type'              => $scopeType,
                'scope_idx'               => $scopeType === 'TENANT' ? 0 : $scopeIdx,
                'old_value'               => $old,
                'new_value'               => $new,
                'reason'                  => $reason,
                'create_account_idx'      => $by?->getAuthIdentifier() ?? 0,
                'create_datetime'         => now(),
            ]);

            return true;
        });

        if ($changed) {
            $this->flush();
        }

        return $changed;
    }

    // 캐시 비움 ( 값 변경 · 정의 동기화 후 )
    public function flush(): void {
        Cache::forget($this->cacheKey());
        $this->memo = null;
    }

    // ---- 내부 ----

    private function validateValue(array $def, string $value): void {
        $name = $def['name'];
        match ($def['value_type']) {
            'BOOL'  => in_array($value, ['Y', 'N'], true) ?: throw new PolicyException("[ {$name} ] 은(는) Y / N 이어야 합니다."),
            'INT'   => preg_match('/^-?\d{1,9}$/', $value) ?: throw new PolicyException("[ {$name} ] 은(는) 정수여야 합니다."),
            'ENUM'  => in_array($value, $def['options'], true) ?: throw new PolicyException("[ {$name} ] 에 없는 선택지입니다 : {$value}"),
            default => mb_strlen($value) <= 300 ?: throw new PolicyException("[ {$name} ] 은(는) 300자 이하여야 합니다."),
        };
    }

    private function cast(array $def, ?string $raw): mixed {
        if ($raw === null || $raw === '') {
            return $def['value_type'] === 'BOOL' ? false : null;
        }
        return match ($def['value_type']) {
            'BOOL'  => $raw === 'Y',
            'INT'   => (int) $raw,
            default => $raw,
        };
    }

    private function splitKey(string $key): array {
        $pos = strpos($key, '.');
        return [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    // 업체별 DB 가 되어도 캐시가 섞이지 않도록 DB 이름을 키에 넣는다
    private function cacheKey(): string {
        return 'mes:policy:' . DB::connection()->getDatabaseName();
    }

    private function load(): array {
        return $this->memo ??= Cache::rememberForever($this->cacheKey(), function () {
            $defs = SystemConfig::query()
                ->orderBy('system_config_sort')
                ->get()
                ->mapWithKeys(fn (SystemConfig $r) => [
                    $r->system_config_group_key . '.' . $r->system_config_key => [
                        'group'            => $r->system_config_group_key,
                        'key'              => $r->system_config_key,
                        'name'             => $r->system_config_name ?: $r->system_config_key,
                        'remark'           => $r->system_config_remark,
                        'etc'              => $r->system_config_etc ?? [],
                        'category'         => $r->system_config_category,
                        'value_type'       => $r->system_config_value_type,
                        'value'            => $r->system_config_value,
                        'default_value'    => $r->system_config_default_value,
                        'allowed_scopes'   => array_map('trim', explode(',', $r->system_config_allowed_scopes ?: 'TENANT')),
                        'merge_strategy'   => $r->system_config_merge_strategy,
                        'exempt_value'     => $r->system_config_exempt_value,
                        'exempt_scope'     => $r->system_config_exempt_scope,
                        'priority_scope'   => $r->system_config_priority_scope,
                        'depends_on_key'   => $r->system_config_depends_on_key,
                        'depends_on_value' => $r->system_config_depends_on_value,
                        'editable_by'      => $r->system_config_editable_by,
                        'is_visible'       => $r->system_config_is_visible === 'Y',
                        'options'          => collect(($r->system_config_etc ?? [])['options'] ?? [])->pluck('value')->map(fn ($v) => (string) $v)->all(),
                    ],
                ])
                ->all();

            $overrides = [];
            foreach (DB::table('system_config_override')->get() as $o) {
                $overrides[$o->system_config_group_key . '.' . $o->system_config_key][$o->scope_type][(int) $o->scope_idx] = $o->override_value;
            }

            return ['defs' => $defs, 'overrides' => $overrides];
        });
    }
}

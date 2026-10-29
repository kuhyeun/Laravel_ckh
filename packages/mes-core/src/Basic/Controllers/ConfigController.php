<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\SystemConfig;
use MesCore\Basic\Services\PolicyException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\LotRule;
use MesCore\Config\Services\SiteConfigService;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller {
    // 설정 화면 : 정책 정의( 카탈로그 )로 그린다 — 정책-설정-설계 §8
    //   노출( is_visible )만 내려 주고, 선행 조건 · 수정 가능 여부 · 기본값을 함께 준다
    //   section : system( 시스템 계정만 보고 수정 ) / admin( 업체 관리자 )
    public function getData(PolicyService $policy) {
        $isSystem = (int) (Auth::user()?->user_level ?? 99) === 0;
        $defs     = $policy->definitions();

        $items = collect($defs)
            ->filter(fn ($def) => $def['is_visible'])
            ->filter(fn ($def) => !in_array($def['group'], SiteConfigService::GROUPS, true))   // 사이트설정( 1012 ) 화면이 맡는다
            ->filter(fn ($def) => $isSystem || $def['editable_by'] !== 'SYSTEM_ADMIN')
            ->map(fn ($def, $key) => [
                'group'            => $def['group'],
                'key'              => $def['key'],
                'name'             => $def['name'],
                'remark'           => $def['remark'],
                'etc'              => $def['etc'],   // { type, options, … }
                'category'         => $def['category'],
                'value'            => $def['value'] ?? $def['default_value'] ?? '',
                'is_default'       => $def['value'] === null,
                'default_value'    => $def['default_value'],
                'depends_on_key'   => $def['depends_on_key'],
                'depends_on_value' => $def['depends_on_value'],
                'section'          => $def['editable_by'] === 'SYSTEM_ADMIN' ? 'system' : 'admin',
                'editable'         => $def['editable_by'] === 'TENANT' || $isSystem,
            ])
            ->values();

        // 내려 주지 않은 선행 설정의 값 ( 예: 관리자 화면의 출하 LOT → 시스템 설정 feature.lot ) — 노출 판단용
        $shown = $items->map(fn ($i) => $i['group'] . '.' . $i['key'])->all();
        $dependValues = $items->pluck('depends_on_key')
            ->filter(fn ($key) => $key && !in_array($key, $shown, true) && isset($defs[$key]))
            ->unique()
            ->mapWithKeys(fn ($key) => [$key => $defs[$key]['value'] ?? $defs[$key]['default_value'] ?? ''])
            ->all();

        return response()->json([
            'items'         => $items,
            'depend_values' => (object) $dependValues,
            'is_system'     => $isSystem,
            'warnings'      => $this->warnings(),
        ]);
    }

    public function getValue( string $group, string $key ) {
        $configValue = '';

        if( $group != '' && $key != '' ) {
            $configValue = SystemConfig::get( $group, $key );
        }

        return response()->json(['configValue' => $configValue]);
    }

    public function getGroups( string $group ) {
        $configGroups = [];

        if( $group != '' ) {
            $configGroups = SystemConfig::getGroup( $group );
        }

        return response()->json(['configGroups' => $configGroups]);
    }

    // 설정 저장 : [{ group, key, value }] — 바뀐 항목만 받는다. value = null 이면 기본값으로 되돌림
    //   검증( 권한 · 형태 · 후보 · 선행 조건 )과 이력은 PolicyService 가 한다. 하나라도 실패하면 전부 취소
    public function save(Request $request, PolicyService $policy) {
        $validated = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.group' => ['required', 'string', 'max:50', Rule::notIn(SiteConfigService::GROUPS)],
            'items.*.key'   => 'required|string|max:50',
            'items.*.value' => 'nullable|string|max:300',
        ]);

        // 선행 스위치를 먼저 바꿔야 뒤따르는 설정의 선행 조건 검사가 맞는다 ( 기능 스위치 → 나머지 )
        $items = collect($validated['items'])
            ->sortBy(fn ($i) => $i['group'] === 'feature' ? 0 : 1)
            ->values();

        try {
            $changed = DB::transaction(function () use ($items, $policy) {
                $n = 0;
                foreach ($items as $item) {
                    $n += $policy->set($item['group'] . '.' . $item['key'], $item['value'] ?? null) ? 1 : 0;
                }
                return $n;
            });

            return response()->json(['message' => 'Success', 'changed' => $changed, 'warnings' => $this->warnings()], 200);
        } catch (PolicyException | InvalidArgumentException $e) {
            // 실패하면 트랜잭션은 롤백됐지만 앞서 바뀐 항목이 비운 캐시는 다시 채워야 한다
            $policy->flush();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $policy->flush();
            Log::error('시스템 설정 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 설정 조합으로 생기는 경고 ( 막지 않음 )
    private function warnings(): array {
        return array_values(array_filter([
            LotRule::shippingRuleWarning(),   // W_LOT_NO_SHIP_RULE
        ]));
    }
}

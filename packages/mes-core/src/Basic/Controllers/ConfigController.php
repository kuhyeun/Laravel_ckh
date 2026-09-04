<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\SystemConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller {
    // 화면이 groups 를 구성할 수 있도록 "스키마 + 값"을 통째로 내려준다.
    // ( type 등 UI 메타는 system_config_etc(JSON) 에서 꺼냄. 순서는 DB 순서 )
    public function getData() {
        $items = SystemConfig::orderBy('system_config_group_key')
            ->get()
            ->map(fn ($r) => [
                'group'  => $r->system_config_group_key,
                'key'    => $r->system_config_key,
                'name'   => $r->system_config_name,
                'value'  => $r->system_config_value,
                'remark' => $r->system_config_remark,
                'etc'    => $r->system_config_etc ?? [],   // { type, options, ... }
            ]);

        return response()->json(['items' => $items]);
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

    // 설정 일괄 저장 : [{ group, key, value }] 를 받아 upsert
    public function save( Request $request ) {
        $validated = $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.group' => 'required|string|max:50',
            'items.*.key'   => 'required|string|max:50',
            'items.*.value' => 'nullable|string|max:300',
        ]);

        try {
            DB::transaction(function () use ( $validated ) {
                foreach( $validated['items'] as $item ) {
                    SystemConfig::put( $item['group'], $item['key'], $item['value'] ?? '' );
                }
            });

            return response()->json(['message' => 'Success'], 200);
        } catch( \Throwable $e ) {
            Log::error( '시스템 설정 저장 실패 : ' . $e->getMessage() );

            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

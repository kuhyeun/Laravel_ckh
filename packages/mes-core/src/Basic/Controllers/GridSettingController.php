<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// 그리드 열 설정 ( 공통 — DataGrid 의 setting-key ) : 로그인한 사용자 자신의 설정만 읽고 쓴다
//   열 이름 · 표시 · 순서 · 너비. 어떤 열이 있는지는 화면 코드가 정한다 ( 여기는 받은 그대로 저장 )
class GridSettingController extends Controller {

    private const PAGE_RULE = ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\/]+$/'];
    private const KEY_RULE  = ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/'];

    public function show(Request $request) {
        $v = $request->validate(['page' => self::PAGE_RULE, 'key' => self::KEY_RULE]);
        $row = $this->query($v['page'], $v['key'])->first(['columns']);
        return response()->json(['columns' => $row ? json_decode($row->columns, true) : null]);
    }

    public function save(Request $request) {
        $v = $request->validate([
            'page'             => self::PAGE_RULE,
            'key'              => self::KEY_RULE,
            'columns'          => 'required|array|max:200',
            'columns.*.name'   => 'required|string|max:100',
            'columns.*.header' => 'nullable|string|max:50',
            'columns.*.hidden' => 'nullable|boolean',
            'columns.*.width'  => 'nullable|integer|min:20|max:2000',
        ]);

        // 바뀐 것만 남긴다 ( 빈 이름 · false · 없는 너비는 뺀다 )
        $columns = array_map(fn ($c) => array_filter([
            'name'   => $c['name'],
            'header' => isset($c['header']) && trim($c['header']) !== '' ? trim($c['header']) : null,
            'hidden' => !empty($c['hidden']) ? true : null,
            'width'  => $c['width'] ?? null,
        ], fn ($x) => $x !== null), $v['columns']);

        DB::table('user_grid_setting')->updateOrInsert(
            ['account_idx' => Auth::id(), 'page_component' => $v['page'], 'grid_key' => $v['key']],
            ['columns' => json_encode(array_values($columns), JSON_UNESCAPED_UNICODE), 'update_datetime' => now()],
        );
        return response()->json(['message' => 'Success']);
    }

    // 기본값으로 = 내 설정을 지운다
    public function reset(Request $request) {
        $v = $request->validate(['page' => self::PAGE_RULE, 'key' => self::KEY_RULE]);
        $this->query($v['page'], $v['key'])->delete();
        return response()->json(['message' => 'Success']);
    }

    private function query(string $page, string $key) {
        return DB::table('user_grid_setting')->where('account_idx', Auth::id())->where('page_component', $page)->where('grid_key', $key);
    }
}

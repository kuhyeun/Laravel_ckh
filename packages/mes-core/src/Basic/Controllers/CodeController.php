<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Models\SystemCodeGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CodeController extends Controller {

    // ---- 조회 ----

    // 코드 그룹 전체
    public function groups() {
        $groups = SystemCodeGroup::orderBy('system_code_group_key')->get();

        return response()->json(['groups' => $groups]);
    }

    // 코드 전체 ( group_key 지정 시 해당 그룹만 )
    public function codes(Request $request) {
        $query = SystemCode::query()
            ->orderBy('system_code_group_key')
            ->orderBy('system_code_key');

        if ($request->filled('group_key')) {
            $query->where('system_code_group_key', $request->input('group_key'));
        }

        return response()->json(['codes' => $query->get()]);
    }

    // ---- 코드 그룹 ----

    public function saveGroup(Request $request) {
        $idx = $request->input('system_code_group_idx');

        // validate() 는 try 밖에서 → ValidationException 이 422 로 정상 응답
        $validated = $request->validate([
            'system_code_group_key' => [
                'required', 'string', 'max:100',
                Rule::unique('system_code_group', 'system_code_group_key')->ignore($idx, 'system_code_group_idx'),
            ],
            'system_code_group_name' => 'required|string|max:200',
            'system_code_group_desc' => 'nullable|string|max:200',
        ]);

        try {
            $group = $idx ? SystemCodeGroup::findOrFail($idx) : new SystemCodeGroup();
            $group->fill($validated)->save();

            return response()->json(['message' => 'Success', 'group' => $group], 200);
        } catch (\Throwable $e) {
            Log::error('코드 그룹 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    public function deleteGroup(Request $request) {
        try {
            $group = SystemCodeGroup::find($request->input('system_code_group_idx'));
            if (!$group) {
                return response()->json(['message' => 'NotFound'], 404);
            }

            // 그룹에 속한 코드가 있으면 삭제 차단
            $hasCodes = SystemCode::where('system_code_group_key', $group->system_code_group_key)->exists();
            if ($hasCodes) {
                return response()->json(['message' => 'HasCodes'], 409);
            }

            $group->delete();

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('코드 그룹 삭제 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 코드 ----

    public function saveCode(Request $request) {
        $idx = $request->input('system_code_idx');

        $validated = $request->validate([
            'system_code_group_key' => 'required|string|max:100|exists:system_code_group,system_code_group_key',
            'system_code_key' => [
                'required', 'string', 'max:100',
                // 같은 그룹 내 코드키 유일
                Rule::unique('system_code', 'system_code_key')
                    ->where('system_code_group_key', $request->input('system_code_group_key'))
                    ->ignore($idx, 'system_code_idx'),
            ],
            'system_code_name'  => 'required|string|max:200',
            'system_code_value' => 'nullable|string|max:200',
            'system_code_desc'  => 'nullable|string|max:200',
        ]);

        try {
            $code = $idx ? SystemCode::findOrFail($idx) : new SystemCode();
            $code->fill($validated)->save();

            return response()->json(['message' => 'Success', 'code' => $code], 200);
        } catch (\Throwable $e) {
            Log::error('코드 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    public function deleteCode(Request $request) {
        try {
            $code = SystemCode::find($request->input('system_code_idx'));
            if (!$code) {
                return response()->json(['message' => 'NotFound'], 404);
            }

            $code->delete();

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('코드 삭제 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

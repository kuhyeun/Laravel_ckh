<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Models\SystemCodeGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CodeController extends Controller {

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

    // 그리드 일괄 저장 ( 추가·수정 행 ). 한 행이라도 검증 실패하면 전부 저장하지 않음
    //   검증 오류 키: rows.{index}.{field} → 프론트가 해당 행/컬럼으로 포커스
    public function saveCodes(Request $request) {
        $request->validate([
            'rows' => 'required|array|min:1',
        ]);

        $rows       = $request->input('rows');
        $attributes = [
            'system_code_group_key' => '그룹키',
            'system_code_key'       => '코드키',
            'system_code_name'      => '코드명',
            'system_code_value'     => '코드값',
            'system_code_desc'      => '설명',
        ];

        $errors    = [];
        $validated = [];
        $seenKeys  = [];   // 같은 요청 안의 그룹키+코드키 중복 검사

        foreach ($rows as $i => $row) {
            $idx = $row['system_code_idx'] ?? null;

            $validator = Validator::make($row, [
                'system_code_idx'       => 'nullable|integer|exists:system_code,system_code_idx',
                'system_code_group_key' => 'required|string|max:100|exists:system_code_group,system_code_group_key',
                'system_code_key' => [
                    'required', 'string', 'max:100',
                    Rule::unique('system_code', 'system_code_key')
                        ->where('system_code_group_key', $row['system_code_group_key'] ?? '')
                        ->ignore($idx, 'system_code_idx'),
                ],
                'system_code_name'  => 'required|string|max:200',
                'system_code_value' => 'nullable|string|max:200',
                'system_code_desc'  => 'nullable|string|max:200',
            ], [], $attributes);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["rows.{$i}.{$field}"] = $messages;
                }
                continue;
            }

            $data    = $validator->validated();
            $pairKey = $data['system_code_group_key'] . '|' . $data['system_code_key'];
            if (isset($seenKeys[$pairKey])) {
                $errors["rows.{$i}.system_code_key"] = ['같은 그룹에 동일한 코드키가 중복 입력되었습니다.'];
                continue;
            }
            $seenKeys[$pairKey] = true;

            $validated[] = $data;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            DB::transaction(function () use ($validated) {
                foreach ($validated as $data) {
                    $code = !empty($data['system_code_idx']) ? SystemCode::findOrFail($data['system_code_idx']) : new SystemCode();
                    unset($data['system_code_idx']);
                    $code->fill($data)->save();
                }
            });

            return response()->json(['message' => 'Success', 'saved' => count($validated)], 200);
        } catch (\Throwable $e) {
            Log::error('코드 일괄 저장 실패 : ' . $e->getMessage());

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

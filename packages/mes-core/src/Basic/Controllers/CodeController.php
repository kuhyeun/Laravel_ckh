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
            ->orderBy('sort_order')
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

        // 그룹키는 등록 후 변경 불가
        //   system_code 가 그룹키 문자열로 참조( FK 아님 )하고, 업무 코드에서도 'UNIT' 처럼 키를 직접 쓰므로
        //   바꾸면 하위 코드가 고아가 되거나 화면 참조가 끊긴다 → 필요하면 새 그룹을 만들고 옮긴다
        if ($idx) {
            $currentKey = SystemCodeGroup::where('system_code_group_idx', $idx)->value('system_code_group_key');
            if ($currentKey !== null && $currentKey !== $validated['system_code_group_key']) {
                throw ValidationException::withMessages([
                    'system_code_group_key' => ['그룹키는 등록 후 변경할 수 없습니다.'],
                ]);
            }
        }

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
            'is_use'                => '사용 여부',
            'sort_order'            => '정렬',
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
                'is_use'            => 'required|in:Y,N',
                'sort_order'        => 'nullable|integer|min:0|max:9999',
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
                    $data['sort_order'] = $data['sort_order'] ?? 0;
                    $code->fill($data)->save();
                }
            });

            return response()->json(['message' => 'Success', 'saved' => count($validated)], 200);
        } catch (\Throwable $e) {
            Log::error('코드 일괄 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 ( 그리드 체크박스 ). 한 트랜잭션으로 처리 → 일부만 삭제되는 상황 없음
    //   이미 없는 코드는 건너뛰고, 하나도 없으면 404
    //   ※ 업무 테이블이 코드를 참조하기 시작하면 사용 중 코드는 삭제 차단 → 미사용( is_use = N )으로 내리도록 할 것 ( ADR 0019 )
    public function deleteCodes(Request $request) {
        $validated = $request->validate([
            'system_code_idxs'   => 'required|array|min:1',
            'system_code_idxs.*' => 'integer',
        ]);

        try {
            $deleted = DB::transaction(function () use ($validated) {
                return SystemCode::whereIn('system_code_idx', array_unique($validated['system_code_idxs']))->delete();
            });

            if ($deleted === 0) {
                return response()->json(['message' => 'NotFound'], 404);
            }

            return response()->json(['message' => 'Success', 'deleted' => $deleted], 200);
        } catch (\Throwable $e) {
            Log::error('코드 삭제 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MemberController extends Controller {

    // 사용자(회원) 목록 ( 비밀번호는 모델 $hidden 으로 자동 제외 )
    // 레벨 체계: 0=시스템관리자 1=관리자 10=중간관리자 99=사용자 ( 숫자 클수록 하위 권한 )
    public function list() {
        $myLevel = Auth::user()->user_level;

        $members = User::select(
                'account_idx',
                'user_id',
                'user_name',
                'user_type',
                'user_level',
                'is_use',
                'create_datetime'
            )
            ->where('user_level', '>=', $myLevel)
            ->orderBy('account_idx')
            ->get();

        return response()->json(['members' => $members]);
    }

    // 등록/수정 ( account_idx 있으면 수정, 없으면 신규 )
    public function save(Request $request) {
        $idx     = $request->input('account_idx');
        $myLevel = Auth::user()->user_level;

        // validate() 는 try 밖 → ValidationException 이 422 로 응답
        $validated = $request->validate([
            'user_id'    => ['required', 'string', 'max:50', Rule::unique('account_member', 'user_id')->ignore($idx, 'account_idx')],
            'user_name'  => ['required', 'string', 'max:50'],
            // user_type 은 저장 시 모델에서 user_level 로 자동 결정 ( 입력 불필요 )
            // 내 레벨보다 상위(작은 수)는 부여 불가
            'user_level' => ['required', 'integer', 'min:' . $myLevel],
            'is_use'     => ['required', 'boolean'],
            // 신규는 비밀번호 필수, 수정은 비우면 유지
            'user_pw'    => [$idx ? 'nullable' : 'required', 'string', 'min:8'],
        ]);

        try {
            $user = $idx ? User::find($idx) : new User();
            if ($idx && !$user) {
                return response()->json(['message' => 'NotFound'], 404);
            }
            // 기존 대상이 내 레벨보다 상위면 편집 불가
            if ($idx && $user->user_level < $myLevel) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $user->user_id    = $validated['user_id'];
            $user->user_name  = $validated['user_name'];
            $user->user_level = $validated['user_level'];   // user_type 은 모델 saving 훅이 자동 설정
            $user->is_use     = $validated['is_use'];
            if (!empty($validated['user_pw'])) {
                $user->user_pw = $validated['user_pw'];   // 모델 casts 'hashed' → 자동 해시
            }
            if ($idx) {
                $user->update_account_idx = Auth::id();
            } else {
                $user->create_account_idx = Auth::id();
            }
            $user->save();

            return response()->json(['message' => 'Success', 'member' => $user], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 삭제
    public function delete(Request $request) {
        $myLevel = Auth::user()->user_level;

        try {
            $user = User::find($request->input('account_idx'));
            if (!$user) {
                return response()->json(['message' => 'NotFound'], 404);
            }
            // 자기 자신 / 상위 권한자는 삭제 불가
            if ($user->account_idx === Auth::id()) {
                return response()->json(['message' => 'SelfDelete'], 409);
            }
            if ($user->user_level < $myLevel) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $user->delete();

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 삭제 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }
}

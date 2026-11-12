<?php

namespace MesCore\Basic\Controllers;

use MesCore\Http\Controller;
use MesCore\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MemberController extends Controller {

    // 사용자(회원) 목록 ( 비밀번호는 모델 $hidden 으로 자동 제외 )
    // 레벨 체계: 0=시스템관리자 1=관리자 10=중간관리자 99=사용자 ( 숫자 클수록 하위 권한 )
    // 자기 권한보다 낮은 계정만 표시 및 본인 계정은 숨김처리
    public function list() {
        $myIdx   = Auth::user()->account_idx;
        $myLevel = (int) Auth::user()->user_level;

        $members = User::select(
                'account_idx',
                'user_id',
                'user_name',
                'emp_no',
                'dept_code',
                'position_code',
                'user_type',
                'user_level',
                'is_use',
                'create_datetime',
                'update_datetime'
            )
            // 부서 · 직급 이름 ( 공통코드 DEPT · POSITION )
            ->addSelect([
                'dept_name'     => DB::table('system_code')->select('system_code_name')->where('system_code_group_key', 'DEPT')->whereColumn('system_code_key', 'account_member.dept_code')->limit(1),
                'position_name' => DB::table('system_code')->select('system_code_name')->where('system_code_group_key', 'POSITION')->whereColumn('system_code_key', 'account_member.position_code')->limit(1),
            ])
            ->where('user_level', $myLevel === User::SYSTEM_LEVEL ? '>=' : '>', $myLevel)   // 나보다 하위만 ( 시스템 계정은 전부 )
            ->where('account_idx', '!=', $myIdx)
            ->orderBy('account_idx')
            ->get();

        return response()->json(['members' => $members]);
    }

    // 등록/수정 ( account_idx 있으면 수정, 없으면 신규 )
    public function save(Request $request) {
        $idx     = $request->input('account_idx');
        $myLevel = (int) Auth::user()->user_level;

        // validate() 는 try 밖 → ValidationException 이 422 로 응답
        $validated = $request->validate([
            'user_id'    => ['required', 'string', 'max:50', Rule::unique('account_member', 'user_id')->ignore($idx, 'account_idx')],
            'user_name'  => ['required', 'string', 'max:50'],
            'emp_no'        => ['nullable', 'string', 'max:30'],
            'dept_code'     => ['nullable', 'string', 'max:30', Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', 'DEPT')],
            'position_code' => ['nullable', 'string', 'max:30', Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', 'POSITION')],
            // user_type 은 저장 시 모델에서 user_level 로 자동 결정 ( 입력 불필요 )
            // 내 레벨과 같거나 상위( 작은 수 )는 부여 불가 ( 시스템 계정은 같은 레벨까지 )
            'user_level' => ['required', 'integer', 'min:' . $this->minAssignable($myLevel)],
            'is_use'     => ['required', 'boolean'],
            // 신규는 비밀번호 필수, 수정은 비우면 유지
            'user_pw'    => [$idx ? 'nullable' : 'required', 'string', 'min:8'],
        ], ['user_level.min' => '나보다 하위 권한 레벨만 지정할 수 있습니다.']);

        try {
            $user = $idx ? User::find($idx) : new User();
            if ($idx && !$user) {
                return response()->json(['message' => 'NotFound'], 404);
            }
            // 기존 대상이 나와 같거나 상위면 편집 불가 ( 같은 레벨 관리자끼리 비밀번호 변경 · 삭제 방지 )
            if ($idx && !$this->canManage($user, $myLevel)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            $user->user_id    = $validated['user_id'];
            $user->user_name  = $validated['user_name'];
            $user->emp_no        = $validated['emp_no'] ?? null;
            $user->dept_code     = $validated['dept_code'] ?? null;
            $user->position_code = $validated['position_code'] ?? null;
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
        $myLevel = (int) Auth::user()->user_level;

        try {
            $user = User::find($request->input('account_idx'));
            if (!$user) {
                return response()->json(['message' => 'NotFound'], 404);
            }
            // 자기 자신 / 같거나 상위 권한자는 삭제 불가
            if ($user->account_idx === Auth::id()) {
                return response()->json(['message' => 'SelfDelete'], 409);
            }
            if (!$this->canManage($user, $myLevel)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            // 지우지 않고 미사용으로 ( ADR 0019 · 코드 검사 B-6 — 작업자 · 검사자로 남은 과거 기록의 이름이 깨지지 않게 · 로그인 · 고르기에서 빠진다 )
            $user->is_use = false;
            $user->update_account_idx = Auth::id();
            $user->save();

            return response()->json(['message' => 'Success'], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 삭제 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 ( 그리드 체크박스 ). 전부 삭제 가능할 때만 삭제 ( 일부만 지워지는 상황 방지 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'account_idxs'   => ['required', 'array', 'min:1'],
            'account_idxs.*' => ['integer'],
        ]);

        $myLevel = (int) Auth::user()->user_level;
        $users   = User::whereIn('account_idx', array_unique($validated['account_idxs']))->get();

        if ($users->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        // 자기 자신 포함 → 차단
        if ($users->contains('account_idx', Auth::id())) {
            return response()->json(['message' => 'SelfDelete'], 409);
        }
        // 상위 권한자 포함 → 차단 ( 대상 아이디 반환 )
        $forbidden = $users->filter(fn ($u) => !$this->canManage($u, $myLevel));
        if ($forbidden->isNotEmpty()) {
            return response()->json([
                'message'  => 'Forbidden',
                'user_ids' => $forbidden->pluck('user_id')->values(),
            ], 403);
        }

        try {
            DB::transaction(function () use ($users) {
                // 지우지 않고 미사용으로 ( ADR 0019 · 코드 검사 B-6 )
                User::whereIn('account_idx', $users->pluck('account_idx'))->update(['is_use' => 'N', 'update_account_idx' => Auth::id()]);
            });

            return response()->json(['message' => 'Success', 'deleted' => $users->count()], 200);
        } catch (\Throwable $e) {
            Log::error('사용자 선택 삭제 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 관리할 수 있는 대상 : 나보다 하위 레벨( 큰 수 ) — 시스템 계정( 레벨 0 )만 같은 레벨까지
    private function canManage(User $target, int $myLevel): bool {
        return $myLevel === User::SYSTEM_LEVEL ? $target->user_level >= $myLevel : $target->user_level > $myLevel;
    }

    private function minAssignable(int $myLevel): int {
        return $myLevel === User::SYSTEM_LEVEL ? $myLevel : $myLevel + 1;
    }
}

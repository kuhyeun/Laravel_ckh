<?php

namespace MesCore\Auth\Controllers;

use MesCore\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use MesCore\Auth\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class UserController extends Controller {

    // 로그인 성공 후 LANDING
    public function dashboard() {
        return Inertia::render( 'Dashboard' );
    }

    // 로그인
    public function showLoginForm(Request $request) {
        return $this->viewOrAbort( 'user.login' );
    }

    // 로그인 처리
    public function processLogin( Request $request ): RedirectResponse {
        $request->validate([
            'user_id' => ['required'],
            'password' => ['required']
        ]);

        $credentials = [
            'user_id' => $request->input( 'user_id' ),
            'password' => $request->input( 'password' ),
            'is_use' => true
        ];

        if( Auth::attempt( $credentials ) ) {
            $request->session()->regenerate();

            $user = Auth::user();
            Session::put( 'user_idx', $user->account_idx );
            Session::put( 'user_id', $user->user_id );
            Session::put( 'user_name', $user->user_name );
            Session::put( 'user_type', $user->user_type );
            Session::put( 'user_level', $user->user_level );

            return redirect()->route( 'user.dashboard' );
        }

        return back()->withErrors([
            'user_id' => '로그인 정보를 찾을 수 없습니다.',
        ])->onlyInput( 'user_id' );
    }

    // 회원가입
    public function showRegistrationForm() {
        return $this->viewOrAbort( 'user.register' );
    }

    // 회원가입 처리
    public function processRegistration( Request $request ): RedirectResponse {
        $messages = [
            'user_id.required'   => '아이디를 입력해주세요',
            'user_id.unique'     => '이미 사용 중인 아이디입니다.',
            'user_name.required' => '사용자 이름을 입력해주세요',
            'password.required'  => '비밀번호를 입력해주세요.',
            'password.min'       => '비밀번호는 최소 :min 글자 이상이어야 합니다.',
            'password.confirmed' => '비밀번호와 비밀번호 확인이 일치하지 않습니다.',
        ];

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'max:255', 'unique:account_member,user_id'],
            'user_name' => ['required', 'string', 'max: 50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], $messages);

        $user = new User();
        $user->user_id    = $validated['user_id'];
        $user->user_pw    = $validated['password'];
        $user->user_name  = $validated['user_name'];
        $user->user_level = 99;
        $user->is_use     = true;

        $user->save();

        return redirect()->route( 'user.login' )->with( 'success', '회원가입이 완료되었습니다. 로그인해주세요.' );
    }

    // 로그아웃
    public function logout( Request $request ): RedirectResponse {
        $user = Auth::user();
        if ($user) {
            Cache::forget($user->getMenuCacheKey());
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route( 'user.login' );
    }
}

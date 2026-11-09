<?php

namespace MesCore\Auth\Controllers;

use MesCore\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\RedirectResponse;
use MesCore\Basic\Models\SystemConfig;
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
            'is_use' => 'Y'   // DB enum( 'Y','N' )
        ];

        if( Auth::attempt( $credentials ) ) {
            $request->session()->regenerate();
            
            $landing_page = SystemConfig::get( 'Common', 'landing_page', '/user/dashboard' );

            $user = Auth::user();
            Session::put( 'user_idx', $user->account_idx );
            Session::put( 'user_id', $user->user_id );
            Session::put( 'user_name', $user->user_name );
            Session::put( 'user_type', $user->user_type );
            Session::put( 'user_level', $user->user_level );

            return redirect( $landing_page );
        }

        return back()->withErrors([
            'user_id' => '로그인 정보를 찾을 수 없습니다.',
        ])->onlyInput( 'user_id' );
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

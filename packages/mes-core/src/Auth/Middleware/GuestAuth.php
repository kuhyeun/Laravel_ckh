<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Auth\Support\LandingPage;
use Symfony\Component\HttpFoundation\Response;

class GuestAuth {

    // 이미 로그인했으면 로그인 화면 대신 시작 메뉴로 ( 11-10 — 전에는 대시보드 고정 )
    public function handle(Request $request, Closure $next): Response {
        $user = Auth::user();
        if( $user instanceof User ) {
            return redirect( LandingPage::for( $user ) );
        }

        return $next($request);
    }
}

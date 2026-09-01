<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class GuestAuth {

    public function handle(Request $request, Closure $next): Response {
        if( Auth::check() ) {
            return redirect()->route( 'user.dashboard' );
        }

        return $next($request);
    }
}

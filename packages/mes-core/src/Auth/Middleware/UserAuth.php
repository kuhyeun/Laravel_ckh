<?php

namespace MesCore\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAuth {

    public function handle(Request $request, Closure $next): Response {
        if( !$request->session()->has('user_id') ) {
            if( $request->header('X-Inertia') ) {
                return response()->json(['message' => 'Unauthenticated.'], 409)
                    ->header('X-Inertia-Location', route('user.login'));
            }
            return redirect()->route('user.login');
        }

        return $next($request);
    }
}

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \MesCore\Http\HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'auth.admin' => \MesCore\Auth\Middleware\AdminAuth::class,
            'auth.user' => \MesCore\Auth\Middleware\UserAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                return redirect()->back()->withErrors(['error' => '페이지가 만료되었습니다. 다시 시도해주세요.']);
            }

            // 권한/찾을 수 없음 등은 Inertia 에러 페이지로 렌더 ( 앱 레이아웃 유지 )
            // 순수 JSON API 요청( axios 등, X-Inertia 없음 )은 그대로 응답 반환
            $wantsJson = $request->expectsJson() && ! $request->header('X-Inertia');
            if (in_array($status, [403, 404, 503]) && ! $wantsJson) {
                return \Inertia\Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();

<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\EnsureUserIsFaculty;
use App\Http\Middleware\RequirePassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A changed password signs out every other session and "Remember me" cookie on their next request.
        $middleware->authenticateSessions();

        // Laravel Cloud's edge is the only way in, so the visitor's address and scheme it forwards are true; without
        // this, every rate limit would count the whole campus as Cloud's one proxy. The forwarded host is left out,
        // so no request can change the address that links are built with.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'approved' => EnsureUserIsApproved::class,
            'faculty' => EnsureUserIsFaculty::class,
            'password.confirm' => RequirePassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

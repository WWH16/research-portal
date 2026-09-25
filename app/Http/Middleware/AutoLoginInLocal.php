<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary development shortcut: signs every request in as a verified dev user
 * so pages can be worked on without the login screen. Only runs when
 * APP_ENV=local. Remove it from bootstrap/app.php to restore normal login.
 */
class AutoLoginInLocal
{
    public const EMAIL = 'dev@research-portal.test';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') && Auth::guest()) {
            $user = User::firstOrCreate(
                ['email' => self::EMAIL],
                ['name' => 'Dev User', 'password' => Str::random(32)],
            );

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            Auth::login($user);
        }

        return $next($request);
    }
}

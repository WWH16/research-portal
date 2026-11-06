<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away sign-ins, sign-ups and reset link requests that Google's reCAPTCHA v3 scores as a bot. Off until the
 * keys are set, so local work and the tests run without Google.
 */
class VerifyRecaptcha
{
    /** The Fortify form posts checked, with the action each form sends its token for. */
    private const array ACTIONS = ['login.store' => 'login', 'register.store' => 'register', 'password.email' => 'forgot_password'];

    public function handle(Request $request, Closure $next): Response
    {
        $action = self::ACTIONS[$request->route()?->getName()] ?? null;
        $secret = config('services.recaptcha.secret_key');

        if ($action === null || ! $request->isMethod('post') || blank($secret)) {
            return $next($request);
        }

        try {
            $result = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => (string) $request->input('recaptcha_token'),
                'remoteip' => $request->ip(),
            ])->json();
        } catch (ConnectionException) {
            // ponytail: fails open so faculty can still sign in while Google is unreachable; the rate limits still apply.
            return $next($request);
        }

        $human = ($result['success'] ?? false) === true
            && ($result['action'] ?? null) === $action
            && ($result['score'] ?? 0) >= config('services.recaptcha.min_score');

        if (! $human) {
            throw ValidationException::withMessages(['email' => __('We could not confirm that you are not a robot. Please reload the page and try again.')]);
        }

        return $next($request);
    }
}

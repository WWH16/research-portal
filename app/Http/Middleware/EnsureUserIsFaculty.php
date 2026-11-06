<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only faculty through. The Research Office (admins and staff) monitors submissions rather than filing them, so it gets a 403.
 */
class EnsureUserIsFaculty
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isFaculty(), 403, __('The Research Office monitors submissions and does not submit proposals.'));

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only faculty through. Admins monitor submissions rather than filing them, so they get a 403.
 */
class EnsureUserIsFaculty
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isAdmin(), 403, __('Admins monitor submissions and don’t submit proposals.'));

        return $next($request);
    }
}

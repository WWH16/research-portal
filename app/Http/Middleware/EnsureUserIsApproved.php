<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends sign-ups an admin hasn't approved, or has rejected, to the approval notice instead of the portal.
 */
class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isApproved()) {
            return redirect()->route('approval.notice');
        }

        return $next($request);
    }
}

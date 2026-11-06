<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword as BaseRequirePassword;
use Illuminate\Http\RedirectResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class RequirePassword extends BaseRequirePassword
{
    /**
     * A Livewire click that has to confirm the password returns to the page afterwards. Laravel would remember the
     * click's own address, which can't be opened.
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        $response = parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);

        if ($response instanceof RedirectResponse && app(HandleRequests::class)->isLivewireRoute()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return $response;
    }
}

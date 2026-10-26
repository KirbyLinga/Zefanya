<?php

namespace App\Listeners;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Queue\InteractsWithQueue;

class EnforceSingleBrowserOnLogin implements ShouldQueue
{
    use ClearsOtherGuards;
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        $guard = $event->guard;

        // Safe-guard: only clear other role sessions for loginable guards so
        // internal events (e.g. password-reset after-login) do not clear admin
        // sessions unexpectedly. The public loginable guards already follow the
        // "one account per browser" rule; admin is covered here too because
        // admin login goes through UnifiedLoginController-style flows.
        if (! in_array($guard, ['admin', 'seller', 'logistics', 'buyer'], true)) {
            return;
        }

        $this->clearOtherGuards($guard);

        $response = $event->response ?? null;

        if ($response instanceof RedirectResponse) {
            $response->setTargetUrl($response->getTargetUrl());
        }
    }
}

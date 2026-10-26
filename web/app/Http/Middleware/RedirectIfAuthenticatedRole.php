<?php

namespace App\Http\Middleware;

use App\Enums\AuthRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-only gate: if ANY role guard is signed in, send the user to that
 * role's dashboard instead of showing login/registration pages.
 *
 * Read-only: only calls ->check(); never logs anyone out.
 * Logout is NOT guest-only and must stay outside this middleware.
 */
class RedirectIfAuthenticatedRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = AuthRole::current();

        if ($role === null) {
            $response = $next($request);

            // Stop the browser back-button from showing a cached login/register
            // page after the user has signed in.
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        $url = $role->dashboardUrl();

        // Registration/login modals submit via XHR; a 302 would be followed
        // and parsed as JSON. Give them something they can act on.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You are already signed in.',
                'redirect' => $url,
            ], 409);
        }

        // redirect()->to(), not intended(): don't touch the intended-URL slot.
        return redirect()->to($url);
    }
}

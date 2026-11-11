<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections that the app itself must send. Reverse-proxy trust stays
 * in config/trustedproxy.php (empty unless TRUSTED_PROXIES names the connector).
 * A wildcard is never used, so a direct client cannot spoof X-Forwarded-*.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The authenticated chat widget is embedded by our own buyer pages.
        $isChatWidget = $request->routeIs('buyer.messages.index', 'buyer.messages.show')
            && $request->boolean('widget')
            && $request->user('buyer') !== null;
        $frameAncestors = $isChatWidget ? "'self'" : "'none'";

        $response->headers->set('X-Frame-Options', $isChatWidget ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy-Report-Only',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'",
        );

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

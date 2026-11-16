<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Reverse Proxies
    |--------------------------------------------------------------------------
    |
    | Laravel's TrustProxies middleware only reads X-Forwarded-* headers when
    | the connecting peer is in this list. Leaving it empty is the safe
    | default: direct requests cannot spoof their client IP, and HTTPS
    | detection is based on the real connection.
    |
    | Deployment TODO (Cloudflare Tunnel): the repository does not know the
    | address the cloudflared connector uses to reach PHP. Do NOT guess it and
    | do NOT use '*' — a wildcard lets any direct client forge X-Forwarded-For
    | and bypass IP-based rate limiting. Set TRUSTED_PROXIES in the production
    | environment to the exact IP/CIDR of the connector (commonly the loopback
    | or private address it binds to, e.g. "127.0.0.1" when cloudflared and PHP
    | share the same host). Verify with `php artisan about` / a request that
    | logs $request->ip() before enabling it.
    |
    | Multiple entries are comma-separated: "127.0.0.1,10.0.0.0/8".
    |
    | The default is an empty array (not null) so Laravel's automatic
    | "trust everything" fallback for Laravel Cloud / Forge / Vapor hosts can
    | never be triggered by a forged Host header on this deployment.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: [],

];

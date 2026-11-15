<?php

/*
 * Courier Mobile app access (Transfer Couriers). Off by default.
 *
 * Turning it on needs BOTH values below, and the pending courier_accounts
 * migration. Every enable must set a NEW COURIER_ENABLED_SINCE (ISO-8601,
 * not in the future): tokens created before it are refused, so tokens from an
 * earlier enabled period never become valid again.
 *
 * A changed value applies to requests that start after the change. If the
 * configuration is ever cached (php artisan config:cache), the cache must be
 * rebuilt and the PHP server restarted before a change takes effect.
 *
 * The limits are approved owner policy; change them only with approval.
 */
return [
    'enabled' => filter_var(env('COURIER_ENABLED', false), FILTER_VALIDATE_BOOL),

    'enabled_since' => env('COURIER_ENABLED_SINCE'),

    // Provision with `php artisan courier:dummy-hash` before enabling access.
    // Install its single-quoted env line, rebuild cached config and restart
    // workers. Regenerate whenever the hashing driver or cost changes.
    // No fallback: missing/incompatible configuration refuses login/activation
    // uniformly, including valid credentials. Existing sessions are unaffected.
    'dummy_hash' => env('COURIER_DUMMY_HASH'),

    'activation_ttl_hours' => 24,

    'activation_max_attempts' => 5,

    'password_min_length' => 8,

    'login_decay_minutes' => 15,

    'login_max_per_id' => 5,

    'login_max_per_ip' => 30,

    'issue_max_per_provider_per_hour' => 30,

    'history_per_page' => 20,

    'list_limit' => 50,
];

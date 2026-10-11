<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sign-In (Laravel Socialite)
    |--------------------------------------------------------------------------
    |
    | Used by App\Http\Controllers\Auth\GoogleAuthController to link an
    | existing buyer/seller/logistics_provider row to a Google account.
    | Register the exact redirect URI from GOOGLE_REDIRECT_URI in Google
    | Cloud Console — one URI covers both the role-agnostic and the
    | role-scoped callback routes, so set it to the role-agnostic callback.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        // Socialite reads exactly this key for the OAuth redirect_uri. The
        // APP_URL fallback keeps the parameter present when GOOGLE_REDIRECT_URI
        // is missing from .env — an empty redirect_uri is what makes Google
        // answer "Error 400: invalid_request / Missing required parameter:
        // redirect_uri". Whatever value wins here must be registered verbatim
        // in Google Cloud Console → Credentials → OAuth client.
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/auth/google/callback'),
    ],

];

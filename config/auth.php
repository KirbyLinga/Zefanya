<?php

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        'buyer' => [
            'driver' => 'session',
            'provider' => 'buyers',
        ],

        'seller' => [
            'driver' => 'session',
            'provider' => 'sellers',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin\Admin::class,
        ],

        'buyers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Buyer\Buyer::class,
        ],

        'sellers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Seller\Seller::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'buyers' => [
            'provider' => 'buyers',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'sellers' => [
            'provider' => 'sellers',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];


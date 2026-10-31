<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super-admin seed credentials
    |--------------------------------------------------------------------------
    |
    | Password used by Database\Seeders\AdminSeeder for the super-admin account.
    | There is deliberately no default: the seeder refuses to run when this is
    | empty, so no known/default password is ever created or committed. Set
    | ADMIN_SEED_PASSWORD in your .env before seeding.
    |
    */

    'seed_password' => env('ADMIN_SEED_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Super-admin seed email
    |--------------------------------------------------------------------------
    |
    | Login address for the seeded super-admin. Required for the same reason as
    | the password: a hardcoded/predictable address is a credential leak. Set
    | ADMIN_SEED_EMAIL in your .env before seeding.
    |
    */

    'seed_email' => env('ADMIN_SEED_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Admin two-factor authentication
    |--------------------------------------------------------------------------
    |
    | When true, admins who have not enrolled a TOTP authenticator are redirected
    | to the setup screen before they can use the console. The password login and
    | the authenticator challenge are always enforced once an admin enrols.
    | Set ADMIN_REQUIRE_2FA=true in production.
    |
    */

    'require_two_factor' => (bool) env('ADMIN_REQUIRE_2FA', false),

    /*
    |--------------------------------------------------------------------------
    | Authenticator issuer label
    |--------------------------------------------------------------------------
    |
    | Shown in the authenticator app next to the account email.
    |
    */

    'two_factor_issuer' => env('ADMIN_TWO_FACTOR_ISSUER', 'Zefanya Admin'),

    /*
    |--------------------------------------------------------------------------
    | Operational alert recipient
    |--------------------------------------------------------------------------
    |
    | Mail address that receives Admin exception alerts (for example a parcel
    | cancelled after two failed pickup attempts). Falls back to the platform
    | MAIL_FROM_ADDRESS when unset.
    |
    */

    'notification_email' => env('ADMIN_NOTIFICATION_EMAIL'),

];

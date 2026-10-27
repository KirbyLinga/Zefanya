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

<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /** Minimum length for a seeded super-admin password. */
    private const MIN_PASSWORD_LENGTH = 12;

    /**
     * Create the super-admin account.
     *
     * Both the email and the password are read from the environment
     * (ADMIN_SEED_EMAIL / ADMIN_SEED_PASSWORD) so no production credential is
     * hardcoded or predictable. There is no default on purpose: a missing or
     * weak value aborts the seed with a clear message instead of creating an
     * account that is known or guessable.
     */
    public function run(): void
    {
        $email = strtolower(trim((string) config('admin.seed_email')));
        $password = (string) config('admin.seed_password');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(
                'ADMIN_SEED_EMAIL is not set to a valid email address. Add it to your .env file before running this seeder.'
            );
        }

        if ($password === '') {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD is not set. Add it to your .env file before running this seeder.'
            );
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD must be at least '.self::MIN_PASSWORD_LENGTH.' characters long.'
            );
        }

        // Create the super admin account
        Admin::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Zefanya Admin',
                'password' => Hash::make($password),
                'role' => AdminRole::SuperAdmin->value,
            ]
        );
    }
}

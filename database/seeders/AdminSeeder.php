<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /**
     * Create the super-admin account.
     *
     * The password is read from ADMIN_SEED_PASSWORD (config('admin.seed_password')).
     * There is no default on purpose: a missing value aborts the seed with a
     * clear message instead of creating an account with a known password.
     */
    public function run(): void
    {
        $password = (string) config('admin.seed_password');

        if ($password === '') {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD is not set. Add it to your .env file before running this seeder.'
            );
        }

        // Create the super admin account
        Admin::firstOrCreate(
            ['email' => 'SuperZefanya@admin.com'],
            [
                'name' => 'Super Zefanya Admin',
                'password' => Hash::make($password),
                'role' => AdminRole::SuperAdmin->value,
            ]
        );
    }
}

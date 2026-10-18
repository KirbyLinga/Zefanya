<?php

namespace Database\Seeders;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Seeder;

/**
 * One courier who can sign in to a live build.
 *
 * Same rules as DemoBuyerSeeder — same purpose, same guards:
 *
 *  - Refuses to run in production.
 *  - Uses `password` as the password, the fixture convention for this project.
 *  - Idempotent: re-seeding neither duplicates nor resets.
 *
 * Without this, a live build cannot reach the courier shell at all: a new
 * logistics provider registration sits at `pending_approval` with no admin
 * around to approve it, so the whole courier side was only verifiable over HTTP.
 */
class DemoCourierSeeder extends Seeder
{
    public const EMAIL = 'courier@zefanya.test';
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoCourierSeeder: skipped — a demo credential must never exist in production.'
            );

            return;
        }

        $provider = LogisticsProvider::firstOrNew(['email' => self::EMAIL]);

        $provider->fill([
            'last_name' => 'Reyes',
            'first_name' => 'Rico',
            'sex' => 'male',
            'contact_no' => '09181234567',
            'birthday' => '1993-03-12',
            'age' => 33,
            'business_name' => 'Rico Reyes Delivery',
            'address_mode' => 'manual',
            'province_name' => 'Laguna',
            'municipality_name' => 'Pila',
            'barangay_name' => 'Poblacion',
            'street' => 'Rizal Street',
            'house_number' => '18',
            'upload_id_path' => 'logistics/demo/courier-id.jpg',
            'dti_permit_path' => 'logistics/demo/courier-dti.jpg',
            'status' => 'approved',
        ]);

        if (! $provider->exists) {
            $provider->password = self::PASSWORD;
        }

        $provider->save();

        $provider->forceFill([
            'email_verified_at' => $provider->email_verified_at ?? now(),
            'approved_at' => $provider->approved_at ?? now(),
        ])->save();

        $this->command?->info(
            'Demo courier: '.self::EMAIL.' / '.self::PASSWORD.' (approved, so a live build can sign in).'
        );
    }
}

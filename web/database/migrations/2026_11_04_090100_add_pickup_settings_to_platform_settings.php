<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'pickup_window_morning' => '08:00-12:00',
        'pickup_window_afternoon' => '13:00-17:00',
        'rider_pickup_first_rate' => '30.00',
        'rider_pickup_additional_rate' => '5.00',
        'rider_delivery_rate' => '50.00',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $key => $value) {
            // Never overwrites a value an admin already saved.
            DB::table('platform_settings')->insertOrIgnore([
                'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The rows hold change history, so they stay.
    }
};

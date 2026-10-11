<?php

namespace Database\Seeders;

use App\Models\Admin\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the super admin account
        Admin::firstOrCreate(
            ['email' => 'SuperZefanya@admin.com'],
            [
                'name' => 'Super Zefanya Admin',
                'password' => Hash::make('Zefanya@6769'),
                'role' => 'admin',
            ]
        );
    }
}

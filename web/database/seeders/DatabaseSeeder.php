<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // `firstOrCreate`, not `create`: this seeder gets run repeatedly on a dev
        // database, and a second `db:seed` used to die on the unique email before
        // it ever reached the catalogues below.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        // The two catalogues a browsable dev database needs: the voucher list the
        // cart offers, and the shops and goods every catalogue screen reads. Both
        // are re-runnable, which matters because this is the command a person
        // repeats after changing a price.
        $this->call([
            VoucherSeeder::class,
            DemoCatalogueSeeder::class,
        ]);
    }
}

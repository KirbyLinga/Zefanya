<?php

namespace Database\Seeders;

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

        // No test user is created here, and that is a fix rather than an
        // omission: Laravel's default scaffolding created an `App\Models\User`,
        // a class this application has never had — it has Buyer, Seller and
        // Admin — so `php artisan db:seed` died with "Class App\Models\User not
        // found" before it reached either catalogue below. Inventing a User model
        // to satisfy a seeder would be building a model nobody authenticates.
        //
        // What is still owed is an admin row to sign in with, because the
        // approval screens are real. That is not seeded here: a demo credential
        // is a decision, not a fixture.

        // The catalogues a browsable dev database needs, the voucher list the cart
        // offers, and the one buyer account a live build can sign in with. All are
        // re-runnable, which matters because this is the command a person repeats
        // after changing a price.
        //
        // The demo orders come last because they need a buyer and stock to place
        // against — and they are placed *through* `OrderPlacer`, so the rows they
        // write are the rows a real checkout writes, stock ledger and all.
        $this->call([
            VoucherSeeder::class,
            DemoCatalogueSeeder::class,
            DemoBuyerSeeder::class,
            DemoOrderSeeder::class,
        ]);
    }
}

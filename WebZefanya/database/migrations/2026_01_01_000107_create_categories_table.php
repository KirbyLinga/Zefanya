<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * categories - The fixed platform categories (Books, Electronics, ...).
 *
 * Belongs to: nothing (a starting table).
 * Used by: products, sellers
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('icon');
            $table->string('label');
            $table->timestamps();
        });

        // Reference rows every install needs.
        DB::table('categories')->insert(array_map(fn (array $row): array => $row + ['created_at' => now(), 'updated_at' => now()], [
            ['id' => 1, 'icon' => 'book-open', 'label' => 'Books and Media'],
            ['id' => 2, 'icon' => 'heart-pulse', 'label' => 'Health and Beauty'],
            ['id' => 3, 'icon' => 'mountain', 'label' => 'Sports and Outdoors'],
            ['id' => 4, 'icon' => 'flower-2', 'label' => 'Home and Garden'],
            ['id' => 5, 'icon' => 'baby', 'label' => 'Kids and Baby'],
            ['id' => 6, 'icon' => 'shirt', 'label' => 'Men\'s Apparel'],
            ['id' => 7, 'icon' => 'sparkles', 'label' => 'Women\'s Apparel'],
            ['id' => 8, 'icon' => 'smartphone', 'label' => 'Electronics and Gadgets'],
            ['id' => 9, 'icon' => 'paw-print', 'label' => 'Pet Supplies'],
            ['id' => 10, 'icon' => 'utensils', 'label' => 'Food and Gourmet'],
            ['id' => 11, 'icon' => 'car', 'label' => 'Automotive & Motorcycle'],
            ['id' => 12, 'icon' => 'sofa', 'label' => 'Furniture and Office Equipment'],
            ['id' => 13, 'icon' => 'gem', 'label' => 'Jewelry and Watches'],
            ['id' => 14, 'icon' => 'pencil-ruler', 'label' => 'Office and School Supplies'],
        ]));
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('categories');
    }
};

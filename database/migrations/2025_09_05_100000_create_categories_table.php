<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('icon');   // lucide icon name, e.g. 'book-open'
            $table->string('label');  // display name, e.g. 'Books and Media'
            $table->timestamps();
        });

        // Fixed, curated list — not admin/seller editable. If you ever need
        // to add a category, add a row here and re-migrate, don't build a
        // CRUD for this.
        $now = now();
        DB::table('categories')->insert(array_map(fn ($row) => $row + [
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['icon' => 'book-open', 'label' => 'Books and Media'],
            ['icon' => 'heart-pulse', 'label' => 'Health and Beauty'],
            ['icon' => 'mountain', 'label' => 'Sports and Outdoors'],
            ['icon' => 'flower-2', 'label' => 'Home and Garden'],
            ['icon' => 'baby', 'label' => 'Kids and Baby'],
            ['icon' => 'shirt', 'label' => "Men's Apparel"],
            ['icon' => 'sparkles', 'label' => "Women's Apparel"],
            ['icon' => 'smartphone', 'label' => 'Electronics and Gadgets'],
            ['icon' => 'paw-print', 'label' => 'Pet Supplies'],
            ['icon' => 'utensils', 'label' => 'Food and Gourmet'],
            ['icon' => 'car', 'label' => 'Automotive & Motorcycle'],
            ['icon' => 'sofa', 'label' => 'Furniture and Office Equipment'],
            ['icon' => 'gem', 'label' => 'Jewelry and Watches'],
            ['icon' => 'pencil-ruler', 'label' => 'Office and School Supplies'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
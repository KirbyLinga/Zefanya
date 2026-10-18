<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The home banner campaign slides.
 *
 * ## Why this is its own table
 *
 * Promo slides are editorial content: a marketing person should be able to
 * add a "Flash Sale" banner without touching code. The alternative — keeping
 * them as constants in mock_data.dart — means a campaign change is a deploy.
 *
 * ## The icon column stores a name string, not a codepoint
 *
 * Flutter's `Icons.local_florist_outlined` is an `IconData` object that cannot
 * cross the wire. Instead, the server stores the Dart name (e.g.
 * `'local_florist_outlined'`) and the app has a lookup table that maps names
 * to `IconData` values. Unknown names fall back to a neutral icon rather than
 * crashing, so a typo in the CMS does not break the carousel.
 *
 * ## layout and ground are enum-like strings
 *
 * Three layouts (`text_left`, `centered_hero`, `icon_split`) and three grounds
 * (`brand_rose`, `sage`, `terracotta`) match the Dart enums in `mock_data.dart`.
 * Using a CHECK constraint keeps the column honest without a separate table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            // Display order — smallest sort_order appears first.
            $table->unsignedTinyInteger('sort_order')->default(0);

            // Whether this slide is shown. A campaign can be toggled off without
            // deletion, and a deleted row vanishes from history.
            $table->boolean('is_active')->default(true);

            $table->string('eyebrow');
            $table->string('title');
            $table->string('subtitle');

            // One of: text_left | centered_hero | icon_split
            $table->string('layout');

            // One of: brand_rose | sage | terracotta
            $table->string('ground');

            // Dart icon name (e.g. 'local_florist_outlined').
            $table->string('icon');

            // Optional second icon used as a background motif.
            $table->string('big_icon')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};

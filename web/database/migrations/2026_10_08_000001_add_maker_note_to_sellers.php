<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `maker_note` to the `sellers` table.
 *
 * This is the "FROM THE MAKER" voice line that appears on the Home screen's
 * SellerStoryCard widget. It is a seller-level field, not a product-level one:
 * a shop speaks in one voice, and the note is the same regardless of which of
 * their products a buyer is looking at.
 *
 * ## Why nullable, not empty-string-default
 *
 * Null means "this seller has not written anything", and that is a meaningful
 * signal: the app hides the maker section rather than showing a blank card.
 * An empty string default would require every call site to add a `!= ''` guard
 * to reproduce the same behaviour, which is one extra rule to remember and one
 * more place to forget it.
 *
 * MySQL forbids defaults on TEXT columns, which is also why the Reviews
 * migration uses nullable TEXT (see that migration's comment). The controller
 * passes '' explicitly when no note is provided; null in the database means
 * nobody has set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->text('maker_note')->nullable()->after('business_name');
        });
    }

    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->dropColumn('maker_note');
        });
    }
};

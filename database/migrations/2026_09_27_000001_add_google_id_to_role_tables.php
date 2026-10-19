<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Sign-In support for the three loginable role tables.
 *
 * `google_id` links a row to the Google account that signs in for it. It is
 * unique per table and is written only by Auth\GoogleAuthController from the
 * verified Google payload — never from request data (project rule #9).
 *
 * `password` becomes nullable because a Google-only account never sets one.
 * The `password => 'hashed'` cast on all three models already passes NULL
 * through untouched, so no model change is required for that.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const ROLE_TABLES = ['buyers', 'sellers', 'logistics_providers'];

    public function up(): void
    {
        foreach (self::ROLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('google_id')->nullable()->unique()->after('password');
                $blueprint->string('password')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::ROLE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // NOTE: reverting `password` to NOT NULL fails if any Google-only
                // account (password IS NULL) still exists — clear those rows or
                // set a password for them before rolling this back.
                $blueprint->string('password')->nullable(false)->change();
                $blueprint->dropUnique(['google_id']);
                $blueprint->dropColumn('google_id');
            });
        }
    }
};

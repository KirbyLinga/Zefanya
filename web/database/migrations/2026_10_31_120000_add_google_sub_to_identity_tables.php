<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H2: the Google subject identifier, nullable and unique per identity table.
     *
     * The app authenticates buyers and logistics providers from their own
     * tables; `users` is the framework default and is kept in step so a future
     * shared identity table does not need a second migration. A null is not an
     * identity; multiple nulls are allowed by every supported database.
     */
    public function up(): void
    {
        foreach (['users', 'buyers', 'logistics_providers'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'google_sub')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('google_sub')->nullable()->unique()->after('email');
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'buyers', 'logistics_providers'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'google_sub')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['google_sub']);
                $table->dropColumn('google_sub');
            });
        }
    }
};

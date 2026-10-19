<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration rows are never hard-deleted on re-registration. Instead the
     * old row is archived (archived_at) and its unique e-mail is tombstoned,
     * with the original address preserved in registration_email so the
     * rejection / approval trail stays intact.
     */
    public function up(): void
    {
        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('archived_at')->nullable()->after('status');
                $blueprint->string('registration_email')->nullable()->after('archived_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['buyers', 'sellers', 'logistics_providers'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['archived_at', 'registration_email']);
            });
        }
    }
};

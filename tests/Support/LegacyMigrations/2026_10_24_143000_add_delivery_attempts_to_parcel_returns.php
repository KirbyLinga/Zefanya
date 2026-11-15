<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `delivery_attempts` to `parcel_returns` for existing databases that
 * ran an earlier version of `2026_10_24_050133_create_physical_parcel_returns`
 * without this column.
 *
 * Fresh installations already receive `delivery_attempts` from the original
 * migration, so this migration is a no-op on those databases. Existing
 * databases missing the column get it added safely via Schema::hasColumn().
 *
 * No legacy records are modified automatically. Records with null
 * origin_provider_id/origin_hub_id (legacy parcels without routing/custody
 * data) require manual verification — see TODO below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('parcel_returns', 'delivery_attempts')) {
            Schema::table('parcel_returns', function (Blueprint $table): void {
                $table->unsignedTinyInteger('delivery_attempts')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('parcel_returns', 'delivery_attempts')) {
            Schema::table('parcel_returns', function (Blueprint $table): void {
                $table->dropColumn('delivery_attempts');
            });
        }
    }
};

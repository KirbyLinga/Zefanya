<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * At most one non-archived Province Hub per provider.
 * active_province_slot = 1 for the live Province Hub; NULL when archived.
 * MySQL UNIQUE allows multiple NULLs, so archived hubs never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            if (! Schema::hasColumn('logistics_hubs', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('logistics_hubs', 'active_province_slot')) {
                $table->unsignedTinyInteger('active_province_slot')->nullable()->after('archived_at');
            }
        });

        if (Schema::hasColumn('logistics_hubs', 'active_province_slot')) {
            // Mark the oldest non-rejected Province Hub per provider as the live slot.
            $providerIds = DB::table('logistics_hubs')
                ->where('hub_type', 'province_hub')
                ->whereNull('archived_at')
                ->distinct()
                ->pluck('logistics_provider_id');

            foreach ($providerIds as $providerId) {
                $hubId = DB::table('logistics_hubs')
                    ->where('logistics_provider_id', $providerId)
                    ->where('hub_type', 'province_hub')
                    ->whereNull('archived_at')
                    ->where('approval_status', '!=', 'rejected')
                    ->orderBy('id')
                    ->value('id');

                if ($hubId === null) {
                    continue;
                }

                DB::table('logistics_hubs')
                    ->where('id', $hubId)
                    ->update(['active_province_slot' => 1]);

                // Soft-archive extra non-rejected Province Hubs (keep rows).
                DB::table('logistics_hubs')
                    ->where('logistics_provider_id', $providerId)
                    ->where('hub_type', 'province_hub')
                    ->whereNull('archived_at')
                    ->where('id', '!=', $hubId)
                    ->update([
                        'archived_at' => now(),
                        'active_province_slot' => null,
                        'is_active' => false,
                    ]);
            }

            Schema::table('logistics_hubs', function (Blueprint $table): void {
                $table->unique(
                    ['logistics_provider_id', 'active_province_slot'],
                    'hubs_one_active_province_uq'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            try {
                $table->dropUnique('hubs_one_active_province_uq');
            } catch (Throwable) {
                // Index may not exist on sqlite test refreshes.
            }
            $drops = array_values(array_filter([
                Schema::hasColumn('logistics_hubs', 'active_province_slot') ? 'active_province_slot' : null,
                Schema::hasColumn('logistics_hubs', 'archived_at') ? 'archived_at' : null,
            ]));
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Province ownership lock: province_code is set at registration; Admin verifies
 * it on approval. Logistics cannot change a verified province.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table): void {
            if (! Schema::hasColumn('logistics_providers', 'province_verified_at')) {
                $table->timestamp('province_verified_at')->nullable()->after('province_name');
            }
            if (! Schema::hasColumn('logistics_providers', 'province_verified_by')) {
                $table->foreignId('province_verified_by')
                    ->nullable()
                    ->after('province_verified_at')
                    ->constrained('admins')
                    ->nullOnDelete();
            }
        });

        // Existing approved providers: treat registration province as verified.
        if (Schema::hasColumn('logistics_providers', 'province_verified_at')) {
            DB::table('logistics_providers')
                ->where('status', 'approved')
                ->whereNotNull('province_code')
                ->whereNull('province_verified_at')
                ->update([
                    'province_verified_at' => DB::raw('COALESCE(approved_at, CURRENT_TIMESTAMP)'),
                    'province_verified_by' => DB::raw('approved_by'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table): void {
            if (Schema::hasColumn('logistics_providers', 'province_verified_by')) {
                $table->dropConstrainedForeignId('province_verified_by');
            }
            if (Schema::hasColumn('logistics_providers', 'province_verified_at')) {
                $table->dropColumn('province_verified_at');
            }
        });
    }
};

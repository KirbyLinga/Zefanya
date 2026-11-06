<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            // Rider-controlled availability. Offline Riders receive no NEW
            // jobs (automatic or manual) but keep and finish the ones they
            // hold. Defaults to online so existing Riders keep working.
            $table->boolean('is_online')->default(true)->after('station_hub_id');
            $table->timestamp('online_changed_at')->nullable()->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->dropColumn(['is_online', 'online_changed_at']);
        });
    }
};

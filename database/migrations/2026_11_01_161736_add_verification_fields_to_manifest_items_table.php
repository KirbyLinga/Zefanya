<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('manifest_items', function (Blueprint $table) {
            // Dispatch verification fields — persist the scan instead of cache
            $table->timestamp('verified_for_dispatch_at')->nullable()->after('issue_recorded_at');
            $table->string('verified_for_dispatch_by_type', 20)->nullable()->after('verified_for_dispatch_at');
            $table->unsignedBigInteger('verified_for_dispatch_by_id')->nullable()->after('verified_for_dispatch_by_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manifest_items', function (Blueprint $table) {
            $table->dropColumn(['verified_for_dispatch_at', 'verified_for_dispatch_by_type', 'verified_for_dispatch_by_id']);
        });
    }
};

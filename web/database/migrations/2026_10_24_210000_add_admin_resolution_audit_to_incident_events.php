<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_events', function (Blueprint $table): void {
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->text('resolution_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incident_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by_admin_id');
            $table->dropColumn('resolution_reason');
        });
    }
};

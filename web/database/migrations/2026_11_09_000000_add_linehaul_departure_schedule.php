<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->dateTime('scheduled_departure_at')->nullable();
            $table->unsignedInteger('schedule_version')->default(0);
        });
        Schema::table('manifest_audit_logs', function (Blueprint $table): void {
            $table->json('schedule_change')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manifest_audit_logs', fn (Blueprint $table) => $table->dropColumn('schedule_change'));
        Schema::table('manifests', fn (Blueprint $table) => $table->dropColumn(['scheduled_departure_at', 'schedule_version']));
    }
};

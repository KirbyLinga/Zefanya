<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_connections', function (Blueprint $table): void {
            // Retain an origin-leading index for the foreign key before dropping uniqueness.
            $table->index(['origin_hub_id', 'destination_hub_id', 'is_active'], 'transport_connection_lookup');
        });
        Schema::table('transport_connections', function (Blueprint $table): void {
            $table->dropUnique('unique_transport_connection');
        });
    }

    public function down(): void
    {
        // May fail if multiple modes/history now share endpoints; reconcile before rollback.
        Schema::table('transport_connections', function (Blueprint $table): void {
            $table->unique(['origin_hub_id', 'destination_hub_id'], 'unique_transport_connection');
        });
        Schema::table('transport_connections', function (Blueprint $table): void {
            $table->dropIndex('transport_connection_lookup');
        });
    }
};

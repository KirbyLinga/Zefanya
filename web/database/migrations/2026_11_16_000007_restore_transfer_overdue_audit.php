<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['manifests', 'parcel_handoffs'] as $table) {
            foreach (['overdue_notified_at', 'overdue_escalated_at'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->timestamp($column)->nullable());
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Transfer notification audit history must be retained.');
    }
};

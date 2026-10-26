<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incidents', 'status')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->string('status', 24)->default('open');
                $table->index(['incident_seller_order_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Preserve incident lifecycle data when rolling back this repair.
    }
};

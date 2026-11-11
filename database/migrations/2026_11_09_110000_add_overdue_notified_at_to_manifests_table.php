<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the sending company was told a dispatched batch is overdue, so the
 * reminder is sent once. Additive column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->timestamp('overdue_notified_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table): void {
            $table->dropColumn('overdue_notified_at');
        });
    }
};

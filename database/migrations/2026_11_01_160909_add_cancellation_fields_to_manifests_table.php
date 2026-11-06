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
        Schema::table('manifests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('received_by_id');
            $table->string('cancelled_by_type', 20)->nullable()->after('cancelled_at');
            $table->unsignedBigInteger('cancelled_by_id')->nullable()->after('cancelled_by_type');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by_type', 'cancelled_by_id', 'cancellation_reason']);
        });
    }
};

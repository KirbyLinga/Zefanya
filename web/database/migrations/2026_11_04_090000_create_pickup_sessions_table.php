<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->string('pickup_address_hash', 40);
            $table->string('pickup_address', 500)->nullable();
            $table->string('pickup_barangay_code', 20)->nullable();
            $table->string('window_key', 20);
            $table->timestamp('window_starts_at');
            $table->timestamp('window_ends_at');
            // OPEN accepts parcels, IN_PROGRESS started at the seller (membership frozen),
            // then COMPLETED, MISSED (nobody came) or CANCELLED.
            $table->string('status', 20)->default('OPEN');
            // Rates in force when the session was created. Later setting changes never touch them.
            $table->unsignedInteger('first_minor');
            $table->unsignedInteger('additional_minor');
            // False when this visit continues a recent one: no second first-parcel fee.
            $table->boolean('first_payable')->default(true);
            $table->unsignedBigInteger('continuation_of_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 40)->nullable();
            // Set only while OPEN: one open session per seller, address, provider, window and rider.
            $table->string('open_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['courier_id', 'status']);
            $table->index(['seller_id', 'courier_id', 'closed_at']);
            $table->index(['status', 'window_ends_at']);
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('pickup_session_id')->nullable()->constrained('pickup_sessions')->nullOnDelete();
            $table->string('pickup_window_key', 20)->nullable();
            $table->timestamp('pickup_window_starts_at')->nullable();
            $table->timestamp('pickup_window_ends_at')->nullable();
            $table->unsignedTinyInteger('pickup_missed_windows')->default(0);

            $table->index(['status', 'pickup_window_ends_at']);
        });

        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->foreignId('pickup_session_id')->nullable()->constrained('pickup_sessions')->restrictOnDelete();
            // 1 only on the one first-parcel payment of a session; null otherwise.
            $table->unsignedTinyInteger('session_first')->nullable();

            $table->unique(['pickup_session_id', 'session_first']);
        });
    }

    public function down(): void
    {
        Schema::table('rider_earnings', function (Blueprint $table): void {
            $table->dropUnique(['pickup_session_id', 'session_first']);
            $table->dropConstrainedForeignId('pickup_session_id');
            $table->dropColumn('session_first');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropIndex(['status', 'pickup_window_ends_at']);
            $table->dropConstrainedForeignId('pickup_session_id');
            $table->dropColumn(['pickup_window_key', 'pickup_window_starts_at', 'pickup_window_ends_at', 'pickup_missed_windows']);
        });

        Schema::dropIfExists('pickup_sessions');
    }
};

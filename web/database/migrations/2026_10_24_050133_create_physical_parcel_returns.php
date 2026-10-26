<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', fn (Blueprint $table) => $table->json('pickup_address_snapshot')->nullable());
        foreach (['manifests', 'parcel_handoffs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('direction', 12)->default('forward'));
        }
        Schema::create('parcel_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('RETURN_REQUESTED');
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->text('reason');
            $table->text('exception_reason')->nullable();
            $table->foreignId('origin_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('origin_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->json('seller_address')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('origin_arrived_at')->nullable();
            $table->timestamp('seller_received_at')->nullable();
            $table->foreignId('received_by_seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->string('handover_code_hash', 64)->nullable();
            $table->string('handover_nonce', 64)->nullable();
            $table->timestamp('handover_expires_at')->nullable();
            $table->string('stock_condition', 24)->default('pending_review');
            $table->timestamp('stock_restored_at')->nullable();
            $table->boolean('financial_review_required')->default(false);
            $table->timestamps();
            $table->index(['status', 'requested_at']);
            $table->index(['origin_provider_id', 'status']);
        });
        Schema::create('parcel_return_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_return_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 40);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['parcel_return_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_return_events');
        Schema::dropIfExists('parcel_returns');
        foreach (['manifests', 'parcel_handoffs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('direction'));
        }
        Schema::table('seller_orders', fn (Blueprint $table) => $table->dropColumn('pickup_address_snapshot'));
    }
};

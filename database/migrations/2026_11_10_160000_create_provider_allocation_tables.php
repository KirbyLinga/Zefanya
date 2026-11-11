<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-04: parcels handled by 3+ Logistics providers. The two-provider 40/60 split
 * is untouched. For longer chains we only record WHO handled the parcel; the
 * amounts stay empty until an Admin approves an allocation policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_policy_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->text('policy_note');
            $table->timestamp('approved_at');
            $table->timestamps();
        });

        Schema::create('parcel_provider_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('role', 20); // origin | intermediate | destination
            $table->unsignedSmallInteger('sequence');
            $table->unsignedBigInteger('share_minor')->nullable(); // null = no approved policy yet
            $table->string('status', 20)->default('pending_policy');
            $table->foreignId('policy_approval_id')->nullable()->constrained('allocation_policy_approvals')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['seller_order_id', 'logistics_provider_id'], 'parcel_allocations_parcel_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_provider_allocations');
        Schema::dropIfExists('allocation_policy_approvals');
    }
};

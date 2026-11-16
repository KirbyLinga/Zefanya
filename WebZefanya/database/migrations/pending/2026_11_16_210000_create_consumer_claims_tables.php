<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('basis', 30);
            $table->string('category', 40);
            $table->string('product_name');
            $table->text('description');
            $table->text('warranty_details')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['buyer_id', 'submission_key']);
            $table->index(['order_item_id', 'category', 'status']);
            $table->index(['seller_id', 'status']);
        });
        Schema::create('consumer_claim_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_claim_id')->constrained('consumer_claims')->restrictOnDelete();
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id');
            $table->uuid('submission_key');
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('message');
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['consumer_claim_id', 'actor_type', 'actor_id', 'submission_key'], 'consumer_claim_update_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_claim_updates');
        Schema::dropIfExists('consumer_claims');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'status'], 'inventory_exceptions_order_status_idx');
            $table->index(['status', 'created_at'], 'inventory_exceptions_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_exceptions');
    }
};

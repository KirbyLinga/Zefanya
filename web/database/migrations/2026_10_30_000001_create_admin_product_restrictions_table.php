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
        Schema::create('admin_product_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->string('seller_name');
            $table->text('reason');
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->timestamp('created_at');
            $table->index(['product_id', 'created_at'], 'admin_product_restriction_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_product_restrictions');
    }
};

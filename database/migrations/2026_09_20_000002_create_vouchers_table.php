<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            // VARCHAR, never a native MySQL ENUM (project rule #6). Values are
            // constrained in application code by App\Enums\VoucherType and
            // cast on the model.
            $table->string('type');
            // Stored in centavos for fixed type; as a percent integer (0-100) for percent type
            $table->unsignedBigInteger('value');
            // Minimum order subtotal in centavos before this voucher can be used; null = no minimum
            $table->unsignedBigInteger('min_spend')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};

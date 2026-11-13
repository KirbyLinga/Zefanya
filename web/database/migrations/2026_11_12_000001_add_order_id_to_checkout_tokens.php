<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_tokens', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reconciled_rejected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('reconciled_rejected_at');
        });
    }
};

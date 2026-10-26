<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->foreignId('confirmed_by_id')->nullable()
                ->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('received_amount_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by_id');
            $table->dropColumn(['confirmed_at', 'received_amount_minor']);
        });
    }
};

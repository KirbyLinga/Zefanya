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
        Schema::table('products', function (Blueprint $table): void {
            $table->text('restriction_reason')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->unsignedBigInteger('restricted_by')->nullable();
            $table->foreign('restricted_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['restricted_by']);
            $table->dropColumn(['restriction_reason', 'restricted_at', 'restricted_by']);
        });
    }
};

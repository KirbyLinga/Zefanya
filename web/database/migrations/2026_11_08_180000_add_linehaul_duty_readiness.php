<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linehaul_drivers', function (Blueprint $table): void {
            $table->boolean('on_duty')->default(false);
            $table->timestamp('duty_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('duty_location')->nullable();
        });
        Schema::create('linehaul_duty_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_driver_id')->constrained('linehaul_drivers')->restrictOnDelete();
            $table->string('action', 12);
            $table->boolean('passed')->nullable();
            $table->json('checklist')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linehaul_duty_checks');
        Schema::table('linehaul_drivers', function (Blueprint $table): void {
            $table->dropColumn(['on_duty', 'duty_checked_at', 'last_seen_at', 'duty_location']);
        });
    }
};

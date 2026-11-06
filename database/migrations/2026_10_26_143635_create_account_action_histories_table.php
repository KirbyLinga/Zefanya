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
        Schema::create('account_action_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('account_type', 20);
            $table->unsignedBigInteger('account_id');
            $table->string('account_name');
            $table->string('account_email');
            $table->string('action', 20);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['account_type', 'account_id', 'created_at'], 'account_action_history_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_action_histories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('platform_setting_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_setting_id')->constrained('platform_settings')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('setting_key', 80);
            $table->text('previous_value');
            $table->text('new_value');
            $table->timestamp('created_at')->index();
        });

        // Record existing metadata only. No operational rule reads this store.
        DB::table('platform_settings')->insert([
            ['key' => 'platform_name', 'value' => (string) config('app.name'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'contact_email', 'value' => (string) config('mail.from.address'), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_setting_histories');
        Schema::dropIfExists('platform_settings');
    }
};

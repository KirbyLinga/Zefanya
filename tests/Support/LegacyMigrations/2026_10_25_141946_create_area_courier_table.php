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
        Schema::create('area_courier', function (Blueprint $table): void {
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->cascadeOnDelete();
            $table->primary(['area_id', 'courier_id']);
            $table->index('courier_id');
        });
        DB::table('couriers')->whereNotNull('area_id')->orderBy('id')->chunkById(500, function ($couriers): void {
            DB::table('area_courier')->insertOrIgnore($couriers->map(fn ($courier) => [
                'area_id' => $courier->area_id, 'courier_id' => $courier->id,
            ])->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_courier');
    }
};

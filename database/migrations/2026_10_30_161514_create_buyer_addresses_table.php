<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained()->cascadeOnDelete();
            $table->enum('address_mode', ['api', 'manual'])->default('api');
            $table->string('province_code')->nullable();
            $table->string('province_name')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('municipality_name')->nullable();
            $table->string('barangay_code')->nullable();
            $table->string('barangay_name')->nullable();
            $table->string('street')->nullable();
            $table->string('house_number')->nullable();
            $table->string('address_detail')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_default')->default(false);
            // 1 for the default row, null otherwise. A unique index allows many nulls
            // and refuses a second default for the same buyer.
            $table->unsignedTinyInteger('default_marker')->nullable();
            $table->timestamps();

            $table->unique(['buyer_id', 'default_marker']);
        });

        $now = now();
        DB::table('buyers')
            ->where(function ($query): void {
                $query->whereNotNull('street')->orWhereNotNull('municipality_name');
            })
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $buyer) use ($now): void {
                DB::table('buyer_addresses')->insert([
                    'buyer_id' => $buyer->id,
                    'address_mode' => $buyer->address_mode ?: 'api',
                    'province_code' => $buyer->province_code,
                    'province_name' => $buyer->province_name,
                    'municipality_code' => $buyer->municipality_code,
                    'municipality_name' => $buyer->municipality_name,
                    'barangay_code' => $buyer->barangay_code,
                    'barangay_name' => $buyer->barangay_name,
                    'street' => $buyer->street,
                    'house_number' => $buyer->house_number,
                    'address_detail' => $buyer->address_detail,
                    'postal_code' => $buyer->postal_code,
                    'is_default' => true,
                    'default_marker' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_addresses');
    }
};

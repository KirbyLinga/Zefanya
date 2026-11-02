<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_otps', function (Blueprint $table): void {
            // Readable copy for the buyer's order page; code_hash still does the verifying.
            $table->text('code_encrypted')->nullable()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_otps', function (Blueprint $table): void {
            $table->dropColumn('code_encrypted');
        });
    }
};

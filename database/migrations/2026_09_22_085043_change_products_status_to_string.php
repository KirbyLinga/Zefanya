<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * products.status was originally created as an enum. It is a plain string
     * (varchar(20), default 'draft') in every deployed database — this
     * migration converts the enum column to varchar on migrate:fresh runs.
     * The previous version of this file was broken boilerplate that targeted
     * a non-existent table named "string".
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('status', 20)->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft')->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admin_product_restrictions - Admin blocks placed on a product.
 *
 * Belongs to:
 *   - admins (admin_id)
 *   - products (product_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('admin_product_restrictions')) {
            return;
        }

        Schema::create('admin_product_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->string('seller_name');
            $table->text('reason');
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->timestamp('created_at');

            $table->index(['product_id', 'created_at'], 'admin_product_restriction_lookup_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('admin_product_restrictions');
    }
};

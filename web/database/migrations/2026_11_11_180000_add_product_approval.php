<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });

        // Listings already on sale keep their public version. New submissions do not.
        DB::table('products')->whereIn('status', ['active', 'inactive'])->whereNull('approved_at')->update([
            'approved_at' => now(),
        ]);

        Schema::create('product_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('payload');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });

        Schema::create('product_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('product_name');
            $table->string('seller_name');
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_approval_events');
        Schema::dropIfExists('product_revisions');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['approved_at', 'rejection_reason']);
        });
    }
};

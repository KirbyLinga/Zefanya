<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Purchase-time facts beside price_minor. Null on rows placed before
            // these columns existed: their regular price cannot be recovered.
            $table->unsignedInteger('regular_price_minor')->nullable()->after('price_minor');
            // Null-on-delete: a seller may delete an ended sale; the two prices above still tell the story.
            $table->foreignId('store_sale_id')->nullable()->after('regular_price_minor')
                ->constrained('store_sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_sale_id');
            $table->dropColumn('regular_price_minor');
        });
    }
};

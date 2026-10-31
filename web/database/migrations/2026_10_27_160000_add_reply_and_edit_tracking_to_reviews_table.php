<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            // MySQL may be using the composite unique index as the buyer_id
            // foreign-key index. Add a dedicated index before dropping it.
            $table->index('buyer_id');

            // BUY-RATE-003: reviews attach to a purchased order item, not to a
            // buyer/product pair (one order item can only be reviewed once).
            $table->dropUnique(['buyer_id', 'product_id']);
            $table->unique('order_item_id');

            $table->timestamp('buyer_edited_at')->nullable()->after('body');
            $table->text('seller_reply')->nullable()->after('buyer_edited_at');
            $table->timestamp('seller_replied_at')->nullable()->after('seller_reply');
            $table->timestamp('seller_reply_edited_at')->nullable()->after('seller_replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['order_item_id']);
            $table->dropColumn(['buyer_edited_at', 'seller_reply', 'seller_replied_at', 'seller_reply_edited_at']);
            $table->unique(['buyer_id', 'product_id']);
            $table->dropIndex(['buyer_id']);
        });
    }
};

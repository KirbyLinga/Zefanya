<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat messages between a buyer and a contact (seller or courier).
 *
 * ## Conversation key
 *
 * Rather than a separate `conversations` table (which adds a join on every
 * history read), messages carry a stable `conversation_key` — a canonical
 * string like `buyer:3:seller:7` that identifies the pair. Both sides of a
 * pair produce the same key, so "all messages between Alice and Bob" is
 * a single `WHERE conversation_key = ?` with no join.
 *
 * The key is built by the model's `keyFor()` helper, which sorts the two
 * participants so `buyer:3:seller:7` and `seller:7:buyer:3` always resolve
 * to the same string.
 *
 * ## Sender identity (polymorphic)
 *
 * `sender_type` + `sender_id` cover Buyer, Seller and LogisticsProvider
 * without separate columns per role. Laravel's standard Eloquent morphMap
 * maps the class string to the short name stored here.
 *
 * ## Read receipts
 *
 * `read_at` is nullable: null means unread. The app does not show an unread
 * badge yet, but the column costs nothing and avoids an ALTER TABLE when
 * it's added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            // The two-party thread this message belongs to.
            $table->string('conversation_key');

            // Who wrote it.
            $table->string('sender_type');
            $table->unsignedBigInteger('sender_id');

            $table->text('body');

            // Null = unread. Set by the recipient's read-mark call.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The primary read: "all messages for this thread, oldest first".
            // Also covers "is there anything new in this thread?" (conversation_key
            // + created_at descending with a limit of 1).
            $table->index(['conversation_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};

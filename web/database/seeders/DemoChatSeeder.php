<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use App\Models\ChatMessage;
use App\Models\Seller\Seller;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter conversation between the demo buyer and the first seller.
 *
 * ## Purpose
 *
 * Without seeded chat history, hitting GET /api/chat/{contact} returns an
 * empty array for every contact — which is the correct answer for a new
 * account, but useless when verifying the endpoint. A few realistic exchanges
 * make it possible to check the history read, the `is_me` flag, and the
 * timestamp format in one request.
 *
 * ## Idempotency
 *
 * The seeder checks for existing messages on the conversation key before
 * writing, so re-seeding does not duplicate threads.
 *
 * ## Production guard
 *
 * Chat history in production is never seeded — both because it is personal
 * data and because the demo buyer herself is not supposed to exist there.
 */
class DemoChatSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoChatSeeder: skipped — demo chat must never exist in production.'
            );

            return;
        }

        $buyer = Buyer::where('email', DemoBuyerSeeder::EMAIL)->first();
        if ($buyer === null) {
            $this->command?->warn('DemoChatSeeder: demo buyer not found — run DemoBuyerSeeder first.');

            return;
        }

        $seller = Seller::first();
        if ($seller === null) {
            $this->command?->warn('DemoChatSeeder: no seller found — run DemoCatalogueSeeder first.');

            return;
        }

        $key = ChatMessage::keyFor($buyer, $seller);

        // Idempotency: skip if this thread already has messages.
        if (ChatMessage::where('conversation_key', $key)->exists()) {
            $this->command?->info("DemoChatSeeder: thread '{$key}' already exists — skipped.");

            return;
        }

        $now = now();

        // A realistic starter thread — three turns.
        $messages = [
            [
                'sender_type' => 'buyer',
                'sender_id'   => $buyer->id,
                'body'        => 'Hi! Is the linen wrap dress available in a small?',
                'offset'      => 0,
            ],
            [
                'sender_type' => 'seller',
                'sender_id'   => $seller->id,
                'body'        => 'Hello! Yes, we have smalls in stock. Would you like me to reserve one for you?',
                'offset'      => 2,
            ],
            [
                'sender_type' => 'buyer',
                'sender_id'   => $buyer->id,
                'body'        => 'That would be great, thank you!',
                'offset'      => 5,
            ],
        ];

        foreach ($messages as $msg) {
            ChatMessage::create([
                'conversation_key' => $key,
                'sender_type'      => $msg['sender_type'],
                'sender_id'        => $msg['sender_id'],
                'body'             => $msg['body'],
                'created_at'       => $now->copy()->addMinutes($msg['offset']),
                'updated_at'       => $now->copy()->addMinutes($msg['offset']),
            ]);
        }

        $this->command?->info(
            "DemoChatSeeder: seeded 3-message thread between {$buyer->email} and {$seller->business_name}."
        );
    }
}

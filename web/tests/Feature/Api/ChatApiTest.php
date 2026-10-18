<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\ChatMessage;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/chat/{contact}  — read thread history
 * POST /api/chat/{contact} — send a message
 */
class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buyer(): Buyer
    {
        return Buyer::factory()->create(['status' => 'approved']);
    }

    private function seller(): Seller
    {
        // SellerFactory requires line_of_business_id (FK to categories).
        // categories has icon + label, both NOT NULL.
        $category = Category::firstOrCreate(
            ['label' => 'Fashion'],
            ['icon'  => 'shirt'],
        );

        return Seller::factory()->create([
            'business_name'       => 'Willow & Bloom Atelier',
            'line_of_business_id' => $category->id,
        ]);
    }

    private function courier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'first_name' => 'Rico',
            'last_name'  => 'Delos Santos',
            'status'     => 'approved',
        ]);
    }

    /** Inserts a message row directly (bypasses the API). */
    private function seedMessage(string $key, string $senderType, int $senderId, string $body): ChatMessage
    {
        return ChatMessage::create([
            'conversation_key' => $key,
            'sender_type'      => $senderType,
            'sender_id'        => $senderId,
            'body'             => $body,
        ]);
    }

    // ── GET /api/chat/{contact} ───────────────────────────────────────────────

    public function test_guest_cannot_read_thread(): void
    {
        $this->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertUnauthorized();
    }

    public function test_buyer_reads_empty_thread_for_known_seller(): void
    {
        $buyer = $this->buyer();
        $this->seller();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_buyer_reads_thread_with_existing_messages(): void
    {
        $buyer  = $this->buyer();
        $seller = $this->seller();
        $key    = ChatMessage::keyFor($buyer, $seller);

        $this->seedMessage($key, 'buyer',  $buyer->id,  'Is the dress available?');
        $this->seedMessage($key, 'seller', $seller->id, 'Yes, we have smalls in stock.');

        $data = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('Is the dress available?', $data[0]['body']);
        $this->assertSame('Yes, we have smalls in stock.', $data[1]['body']);
    }

    public function test_is_me_flag_is_true_for_buyer_sent_messages(): void
    {
        $buyer  = $this->buyer();
        $seller = $this->seller();
        $key    = ChatMessage::keyFor($buyer, $seller);

        $this->seedMessage($key, 'buyer',  $buyer->id,  'My message.');
        $this->seedMessage($key, 'seller', $seller->id, 'Their reply.');

        $data = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data[0]['is_me'],  'buyer-sent message should have is_me=true');
        $this->assertFalse($data[1]['is_me'], 'seller-sent message should have is_me=false');
    }

    public function test_messages_are_returned_oldest_first(): void
    {
        $buyer  = $this->buyer();
        $seller = $this->seller();
        $key    = ChatMessage::keyFor($buyer, $seller);

        // Insert in reverse chronological order to confirm explicit sort.
        // Use DB::table to bypass Eloquent's automatic timestamp handling and
        // set specific created_at values that guarantee a deterministic order.
        \Illuminate\Support\Facades\DB::table('chat_messages')->insert([
            'conversation_key' => $key,
            'sender_type'      => 'seller',
            'sender_id'        => $seller->id,
            'body'             => 'Second',
            'created_at'       => '2026-01-01 12:01:00',
            'updated_at'       => '2026-01-01 12:01:00',
        ]);
        \Illuminate\Support\Facades\DB::table('chat_messages')->insert([
            'conversation_key' => $key,
            'sender_type'      => 'buyer',
            'sender_id'        => $buyer->id,
            'body'             => 'First',
            'created_at'       => '2026-01-01 12:00:00',
            'updated_at'       => '2026-01-01 12:00:00',
        ]);

        $data = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertOk()
            ->json('data');

        $this->assertSame('First',  $data[0]['body']);
        $this->assertSame('Second', $data[1]['body']);
    }

    public function test_buyer_can_chat_with_courier_by_full_name(): void
    {
        $buyer   = $this->buyer();
        $courier = $this->courier();
        $key     = ChatMessage::keyFor($buyer, $courier);

        $this->seedMessage($key, 'buyer', $buyer->id, 'What is the gate code?');

        $data = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Rico%20Delos%20Santos')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('What is the gate code?', $data[0]['body']);
    }

    public function test_unknown_contact_returns_404(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/chat/Nobody+Here')
            ->assertNotFound();
    }

    public function test_thread_does_not_leak_another_buyers_messages(): void
    {
        $buyer1 = $this->buyer();
        $buyer2 = Buyer::factory()->create(['status' => 'approved']);
        $seller = $this->seller();

        $key1 = ChatMessage::keyFor($buyer1, $seller);
        $key2 = ChatMessage::keyFor($buyer2, $seller);

        $this->seedMessage($key1, 'buyer', $buyer1->id, 'Buyer 1 message.');
        $this->seedMessage($key2, 'buyer', $buyer2->id, 'Buyer 2 message.');

        $data = $this->actingAs($buyer1, 'sanctum')
            ->getJson('/api/chat/Willow%20%26%20Bloom%20Atelier')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Buyer 1 message.', $data[0]['body']);
    }

    // ── POST /api/chat/{contact} ──────────────────────────────────────────────

    public function test_buyer_can_send_a_message(): void
    {
        $buyer = $this->buyer();
        $this->seller();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/chat/Willow%20%26%20Bloom%20Atelier', ['body' => 'Hello!'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Hello!')
            ->assertJsonPath('data.is_me', true);

        $this->assertDatabaseHas('chat_messages', ['body' => 'Hello!']);
    }

    public function test_send_returns_stored_message_with_id_and_timestamp(): void
    {
        $buyer = $this->buyer();
        $this->seller();

        $data = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/chat/Willow%20%26%20Bloom%20Atelier', ['body' => 'Hi there!'])
            ->assertCreated()
            ->json('data');

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('sent_at', $data);
        $this->assertNotEmpty($data['sent_at']);
    }

    public function test_empty_body_is_rejected(): void
    {
        $buyer = $this->buyer();
        $this->seller();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/chat/Willow%20%26%20Bloom%20Atelier', ['body' => ''])
            ->assertUnprocessable();
    }

    public function test_missing_body_is_rejected(): void
    {
        $buyer = $this->buyer();
        $this->seller();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/chat/Willow%20%26%20Bloom%20Atelier', [])
            ->assertUnprocessable();
    }

    public function test_send_to_unknown_contact_returns_404(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/chat/Nobody+Here', ['body' => 'Hello!'])
            ->assertNotFound();
    }

    public function test_guest_cannot_send_message(): void
    {
        $this->seller();

        $this->postJson('/api/chat/Willow%20%26%20Bloom%20Atelier', ['body' => 'Hello!'])
            ->assertUnauthorized();
    }
}

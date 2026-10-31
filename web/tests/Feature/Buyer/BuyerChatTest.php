<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
    }

    private function conversation(): Conversation
    {
        return Conversation::create(['buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id]);
    }

    public function test_buyer_can_start_a_conversation_from_a_product_and_reopens_the_same_one(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);

        foreach ([1, 2] as $attempt) {
            $id = $this->actingAs($this->buyer, 'buyer')
                ->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'product_id' => $product->id])
                ->assertOk()
                ->json('conversation_id');
        }

        $conversation = Conversation::sole();
        $this->assertSame($conversation->id, $id);
        $this->assertSame($product->id, $conversation->product_id);
    }

    public function test_buyer_can_start_a_conversation_from_their_own_order(): void
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id]);
        SellerOrder::factory()->create(['order_id' => $order->id, 'seller_id' => $this->seller->id]);

        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'order_id' => $order->id])
            ->assertOk();

        $this->assertSame($order->id, Conversation::sole()->order_id);
    }

    public function test_conversation_context_must_connect_the_buyer_and_seller(): void
    {
        $this->actingAs($this->buyer, 'buyer');

        $otherProduct = Product::factory()->create(['seller_id' => Seller::factory()->create(['status' => 'approved'])->id]);
        $this->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'product_id' => $otherProduct->id])
            ->assertStatus(422)->assertJsonValidationErrors('product_id');

        $someoneElsesOrder = Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id]);
        SellerOrder::factory()->create(['order_id' => $someoneElsesOrder->id, 'seller_id' => $this->seller->id]);
        $this->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'order_id' => $someoneElsesOrder->id])
            ->assertStatus(422)->assertJsonValidationErrors('order_id');

        $otherSellersOrder = Order::factory()->create(['buyer_id' => $this->buyer->id]);
        $this->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'order_id' => $otherSellersOrder->id])
            ->assertStatus(422)->assertJsonValidationErrors('order_id');

        $pending = Seller::factory()->create(['status' => 'pending']);
        $this->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $pending->id])
            ->assertStatus(422)->assertJsonValidationErrors('seller_id');

        $this->assertSame(0, Conversation::count());
    }

    public function test_buyer_chat_pages_render(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->buyer, 'buyer');

        $this->get(route('buyer.messages.index'))->assertOk()->assertSee($this->seller->business_name);
        $this->get(route('buyer.messages.show', $conversation))->assertOk()->assertSee('id="messageForm"', false);
    }

    public function test_buyer_sends_text_with_the_same_length_rules(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->buyer, 'buyer');

        foreach ([str_repeat('a', 1001), '   '] as $body) {
            $this->postJson(route('buyer.messages.store'), ['conversation_id' => $conversation->id, 'body' => $body])
                ->assertStatus(422)->assertJsonValidationErrors('body');
        }

        $this->postJson(route('buyer.messages.store'), ['conversation_id' => $conversation->id, 'body' => str_repeat('a', 1000)])
            ->assertOk();

        $this->assertSame('buyer', Message::sole()->sender_role);
    }

    public function test_buyer_sends_photos_with_the_same_file_rules(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->buyer, 'buyer');

        foreach ([UploadedFile::fake()->image('a.gif'), UploadedFile::fake()->image('a.png')->size(5121)] as $bad) {
            $this->post(route('buyer.messages.store'), ['conversation_id' => $conversation->id, 'image' => $bad], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('image');
        }

        $this->post(route('buyer.messages.store'), ['conversation_id' => $conversation->id, 'image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('local')->assertExists(Message::sole()->image_path);
    }

    public function test_buyer_sees_a_photo_sent_by_the_seller(): void
    {
        $conversation = $this->conversation();

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.messages.store'), ['conversation_id' => $conversation->id, 'image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->assertOk();

        $message = Message::sole();
        $url = route('buyer.messages.image', [$conversation, $message]);

        $this->actingAs($this->buyer, 'buyer');
        $this->get(route('buyer.messages.show', $conversation))->assertOk()->assertSee($url, false);
        $this->get(route('buyer.messages.index'))->assertOk()->assertSee('Photo');
        $this->get($url)->assertOk();

        $this->getJson(route('buyer.messages.show', $conversation))
            ->assertOk()
            ->assertJsonPath('messages.0.image_url', $url);
    }

    public function test_other_buyers_cannot_read_send_or_fetch_photos(): void
    {
        $conversation = $this->conversation();
        Storage::disk('local')->put('chat-images/a.png', 'x');
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'seller',
            'sender_id' => $this->seller->id,
            'body' => '',
            'image_path' => 'chat-images/a.png',
        ]);

        $this->actingAs(Buyer::factory()->create(), 'buyer');

        $this->get(route('buyer.messages.image', [$conversation, $message]))->assertForbidden();
        $this->getJson(route('buyer.messages.show', $conversation))->assertForbidden();
        $this->postJson(route('buyer.messages.store'), ['conversation_id' => $conversation->id, 'body' => 'Hello'])->assertForbidden();
        $this->assertSame(1, Message::count());
    }

    public function test_product_page_chat_button_carries_the_real_endpoint(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'status' => 'active']);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('data-url="'.route('buyer.messages.getOrCreate').'"', false);
    }

    public function test_product_chat_renders_the_product_card_on_both_sides_and_updates_context(): void
    {
        Storage::fake('public');
        $first = Product::factory()->create(['seller_id' => $this->seller->id, 'name' => 'First Chat Product', 'price' => '123.45']);
        Storage::disk('public')->put('products/chat.png', 'fixture');
        $first->images()->create(['path' => 'products/chat.png', 'is_primary' => true, 'sort_order' => 0]);
        $id = $this->actingAs($this->buyer, 'buyer')->postJson(route('buyer.messages.getOrCreate'), [
            'seller_id' => $this->seller->id, 'product_id' => $first->id,
        ])->assertOk()->json('conversation_id');
        $this->get(route('buyer.messages.show', $id))->assertOk()
            ->assertSee('data-chat-product="'.$first->id.'"', false)->assertSee('First Chat Product')
            ->assertSee('₱123.45')->assertSee($first->catalogImageUrl(), false);
        $this->actingAs($this->seller, 'seller')->get(route('seller.messages.show', $id))->assertOk()
            ->assertSee('data-chat-product="'.$first->id.'"', false);

        $second = Product::factory()->create(['seller_id' => $this->seller->id, 'name' => 'Second Chat Product']);
        $this->actingAs($this->buyer, 'buyer')->postJson(route('buyer.messages.getOrCreate'), [
            'seller_id' => $this->seller->id, 'product_id' => $second->id,
        ])->assertOk()->assertJsonPath('conversation_id', $id);
        $this->get(route('buyer.messages.show', $id))->assertOk()
            ->assertSee('data-chat-product="'.$second->id.'"', false)->assertDontSee('First Chat Product');
        $this->assertSame(1, Conversation::count());
    }

    public function test_legacy_chat_links_open_messages_and_check_thread_ownership(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.chat.index'))
            ->assertRedirect(route('buyer.messages.index'));
        $this->get(route('buyer.chat.show', $conversation->id))->assertRedirect(route('buyer.messages.show', $conversation));
        $this->get(route('buyer.chat.show', 999999))->assertNotFound();
        $this->actingAs(Buyer::factory()->create(), 'buyer')->get(route('buyer.chat.show', $conversation->id))->assertForbidden();
    }

    public function test_photo_captions_keep_the_text_limit_and_file_types_are_checked_by_content(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.messages.store'), [
            'conversation_id' => $conversation->id, 'body' => str_repeat('a', 1001),
            'image' => UploadedFile::fake()->image('caption.png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->post(route('buyer.messages.store'), [
            'conversation_id' => $conversation->id, 'image' => UploadedFile::fake()->create('fake.jpg', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->post(route('buyer.messages.store'), [
            'conversation_id' => $conversation->id, 'body' => str_repeat('é', 1000),
            'image' => UploadedFile::fake()->image('caption.png')->size(5120),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1000, mb_strlen(Message::sole()->body));
    }

    public function test_guests_must_log_in_to_start_or_open_chat(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id]);
        $this->postJson(route('buyer.messages.getOrCreate'), ['seller_id' => $this->seller->id, 'product_id' => $product->id])->assertUnauthorized();
        $this->getJson(route('buyer.chat.index'))->assertUnauthorized();
        $this->getJson(route('buyer.chat.show', 1))->assertUnauthorized();
        $this->assertSame(0, Conversation::count());
    }
}

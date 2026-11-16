<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SEL-REV chat rules: message 1–1,000 characters, photos JPG/PNG up to 5 MB,
 * private to the conversation.
 */
class SellerChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->conversation = Conversation::create([
            'buyer_id' => Buyer::factory()->create()->id,
            'seller_id' => $this->seller->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function send(array $data, ?Seller $seller = null): TestResponse
    {
        return $this->actingAs($seller ?? $this->seller, 'seller')
            ->post(route('seller.messages.store'), ['conversation_id' => $this->conversation->id] + $data, ['Accept' => 'application/json']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function bodies(): array
    {
        return [
            '1 character' => ['a', true],
            '1,000 characters' => [str_repeat('a', 1000), true],
            '1,001 characters' => [str_repeat('a', 1001), false],
            'empty' => ['', false],
            'only spaces' => ['     ', false],
        ];
    }

    #[DataProvider('bodies')]
    public function test_message_must_be_1_to_1000_characters(string $body, bool $accepted): void
    {
        $response = $this->send(['body' => $body]);

        if ($accepted) {
            $response->assertOk();
            $this->assertSame($body, Message::sole()->body);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors('body');
            $this->assertSame(0, Message::count());
        }
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function images(): array
    {
        return [
            'jpg' => ['photo.jpg', 100, true],
            'jpeg' => ['photo.jpeg', 100, true],
            'png' => ['photo.png', 100, true],
            'png at 5 MB' => ['photo.png', 5120, true],
            'png over 5 MB' => ['photo.png', 5121, false],
            'gif' => ['photo.gif', 100, false],
            'webp' => ['photo.webp', 100, false],
        ];
    }

    #[DataProvider('images')]
    public function test_images_must_be_jpg_or_png_up_to_5_mb(string $name, int $kilobytes, bool $accepted): void
    {
        $response = $this->send(['image' => UploadedFile::fake()->image($name)->size($kilobytes)]);

        if ($accepted) {
            $response->assertOk()->assertJsonPath('image_url', fn ($url) => $url !== null);
            Storage::disk('local')->assertExists(Message::sole()->image_path);
        } else {
            $response->assertStatus(422)->assertJsonValidationErrors('image');
            $this->assertSame(0, Message::count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_non_image_renamed_to_jpg_is_rejected(): void
    {
        // A real file: fakes report their type from the name, not the content.
        $path = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($path, '<?php echo "not a photo";');
        $file = new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);

        $this->send(['image' => $file])->assertStatus(422)->assertJsonValidationErrors('image');

        @unlink($path);

        $this->assertSame(0, Message::count());
    }

    public function test_image_only_message_appears_in_the_thread_and_inbox(): void
    {
        $this->send(['image' => UploadedFile::fake()->image('photo.png')])->assertOk();

        $message = Message::sole();
        $url = route('seller.messages.image', [$this->conversation, $message]);

        $this->assertSame('', $message->body);
        $this->get(route('seller.messages.show', $this->conversation))->assertOk()->assertSee($url, false);
        $this->get(route('seller.messages.index'))->assertOk()->assertSee('Photo');
        $this->get($url)->assertOk();
    }

    public function test_other_sellers_cannot_send_or_fetch_images_and_guests_are_redirected(): void
    {
        $other = Seller::factory()->create(['status' => 'approved']);

        $this->send(['body' => 'Not my chat'], $other)->assertForbidden();
        $this->assertSame(0, Message::count());

        $this->send(['image' => UploadedFile::fake()->image('photo.png')])->assertOk();
        $url = route('seller.messages.image', [$this->conversation, Message::sole()]);

        $this->actingAs($other, 'seller')->get($url)->assertForbidden();

        auth('seller')->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_image_route_rejects_a_message_from_another_conversation(): void
    {
        $this->send(['image' => UploadedFile::fake()->image('photo.png')])->assertOk();

        $mine = Conversation::create([
            'buyer_id' => Buyer::factory()->create()->id,
            'seller_id' => $this->seller->id,
        ]);

        $this->get(route('seller.messages.image', [$mine, Message::sole()]))->assertNotFound();
    }

    public function test_polling_returns_only_new_escaped_messages_and_marks_them_read(): void
    {
        $this->send(['body' => 'Earlier'])->assertOk();
        $lastSeen = Message::sole()->id;

        $incoming = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_role' => 'buyer',
            'sender_id' => $this->conversation->buyer_id,
            'body' => '<b>Is this available?</b>',
        ]);

        $response = $this->getJson(route('seller.messages.show', $this->conversation).'?after='.$lastSeen)->assertOk();

        $this->assertSame([$incoming->id], array_column($response->json('messages'), 'id'));
        $this->assertStringContainsString('&lt;b&gt;Is this available?&lt;/b&gt;', $response->json('messages.0.html'));
        $this->assertNotNull($incoming->fresh()->read_at);
    }

    public function test_message_buyer_button_opens_one_thread_for_the_order(): void
    {
        $this->withoutExceptionHandling();

        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id, 'seller_id' => $this->seller->id]);

        $this->actingAs($this->seller, 'seller');
        $this->get(route('seller.orders.show', $sellerOrder))
            ->assertOk()
            ->assertSee('action="'.route('seller.orders.message', $sellerOrder).'"', false);

        foreach ([1, 2] as $attempt) {
            $response = $this->post(route('seller.orders.message', $sellerOrder));
            $conversation = Conversation::query()->where('buyer_id', $buyer->id)->where('seller_id', $this->seller->id)->sole();
            $response->assertRedirect(route('seller.messages.show', $conversation));
            $this->assertSame($order->id, $conversation->order_id);
        }
    }

    public function test_seller_cannot_open_a_thread_from_another_sellers_order(): void
    {
        $sellerOrder = SellerOrder::factory()->create(['order_id' => Order::factory()->create()->id]);

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.orders.message', $sellerOrder))
            ->assertForbidden();

        $this->assertSame(1, Conversation::count());
    }

    public function test_placeholder_chat_route_is_gone(): void
    {
        $this->assertFalse(Route::has('seller.chat.index'));
    }

    public function test_message_body_is_escaped_in_the_thread(): void
    {
        $this->send(['body' => '<script>alert(1)</script>'])->assertOk();

        $this->get(route('seller.messages.show', $this->conversation))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}

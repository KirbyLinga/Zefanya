<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Review;
use App\Models\Buyer\StoreFollow;
use App\Models\Buyer\Wishlist;
use App\Models\Catalog\Product;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\Conversation;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A deleted buyer loses their personal data, and the business keeps its transaction records. */
class BuyerAccountDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'Correct-Pass9!';

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'password' => self::PASSWORD,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.com',
            'contact_no' => '09171234567',
            'street' => 'Mabini Street',
            'google_id' => 'google-123',
        ]);
    }

    private function deleteAccount(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->buyer, 'buyer')
            ->from(route('buyer.settings.index'))
            ->post(route('buyer.settings.deleteAccount'), $overrides + ['password' => self::PASSWORD, 'confirm' => '1']);
    }

    private function tombstone(): Buyer
    {
        return Buyer::withoutGlobalScope('not_archived')->findOrFail($this->buyer->id);
    }

    private function parcel(OrderStatus $status = OrderStatus::Completed): SellerOrder
    {
        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'reference' => 'ZF-TEST-'.Str::upper(Str::random(6)),
            'total_minor' => 150000,
            'shipping_address' => [
                'name' => 'Maria Santos',
                'phone' => '09171234567',
                'province' => 'Laguna',
                'province_code' => '043400000',
                'municipality' => 'Santa Cruz',
                'municipality_code' => '043426000',
                'barangay' => 'Bagumbayan',
                'barangay_code' => '043426001',
                'street' => 'Mabini Street',
                'house_number' => '12',
                'address_detail' => 'Blue gate',
                'postal_code' => '4009',
                'full' => '12, Mabini Street, Bagumbayan, Santa Cruz, Laguna',
            ],
        ]);

        return SellerOrder::factory()->status($status)->create([
            'order_id' => $order->id,
            'buyer_note' => 'Call Maria at the blue gate',
            'tracking_number' => 'TRK-'.Str::upper(Str::random(8)),
            'subtotal_minor' => 150000,
        ]);
    }

    private function parcelReturn(SellerOrder $parcel, array $attributes = []): ParcelReturn
    {
        return ParcelReturn::query()->forceCreate($attributes + [
            'seller_order_id' => $parcel->id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged',
            'requested_at' => now(),
        ]);
    }

    public function test_a_buyer_with_a_finished_order_can_delete_without_an_error(): void
    {
        $this->parcel();

        $this->deleteAccount()
            ->assertRedirect(route('home'))
            ->assertSessionHas('success', 'Your account has been deleted.');

        $this->assertGuest('buyer');
    }

    public function test_personal_details_are_erased_from_the_account(): void
    {
        $this->deleteAccount()->assertRedirect(route('home'));

        $row = (array) DB::table('buyers')->where('id', $this->buyer->id)->first();
        $this->assertNotNull($row['archived_at']);
        $this->assertSame('deleted-'.$this->buyer->id.'@archive.invalid', $row['email']);
        foreach (['contact_no', 'password', 'google_id', 'remember_token', 'street', 'registration_email', 'settings'] as $column) {
            $this->assertNull($row[$column], $column);
        }

        $stored = json_encode($row);
        foreach (['Maria', 'Santos', 'maria.santos', '09171234567', 'Mabini'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
    }

    public function test_the_transaction_record_survives_without_the_buyers_contact_details(): void
    {
        $parcel = $this->parcel();
        $order = $parcel->order;
        $updatedAt = $order->fresh()->updated_at;

        $this->deleteAccount()->assertRedirect(route('home'));

        $kept = Order::query()->findOrFail($order->id);
        $this->assertSame($this->buyer->id, $kept->buyer_id);
        $this->assertSame($order->reference, $kept->reference);
        $this->assertSame(150000, (int) $kept->total_minor);
        $this->assertEquals($updatedAt, $kept->updated_at);

        $keptParcel = SellerOrder::query()->findOrFail($parcel->id);
        $this->assertSame(OrderStatus::Completed, $keptParcel->status);
        $this->assertSame($parcel->tracking_number, $keptParcel->tracking_number);
        $this->assertSame(150000, (int) $keptParcel->subtotal_minor);
        $this->assertNull($keptParcel->buyer_note);

        $address = $kept->shipping_address;
        $this->assertSame('Deleted buyer', $address['name']);
        $this->assertSame('Santa Cruz', $address['municipality']);
        $this->assertSame('043426000', $address['municipality_code']);
        foreach (['phone', 'street', 'house_number', 'address_detail', 'postal_code'] as $key) {
            $this->assertNull($address[$key], $key);
        }
        $this->assertSame('Bagumbayan, Santa Cruz, Laguna', $address['full']);
    }

    public function test_saved_data_tokens_and_notifications_are_removed(): void
    {
        $product = Product::factory()->create();
        CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id]);
        Wishlist::query()->forceCreate(['buyer_id' => $this->buyer->id, 'product_id' => $product->id]);
        StoreFollow::query()->forceCreate(['buyer_id' => $this->buyer->id, 'seller_id' => Seller::factory()->create()->id]);
        BuyerAddress::query()->forceCreate(['buyer_id' => $this->buyer->id, 'is_default' => false, 'street' => 'Mabini Street']);
        $this->buyer->createToken('phone');
        DatabaseNotification::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $this->buyer->getMorphClass(),
            'notifiable_id' => $this->buyer->id,
            'data' => ['message' => 'Hello Maria'],
        ]);
        DB::table('password_reset_tokens_buyers')->insert(['email' => $this->buyer->email, 'token' => 'hash', 'created_at' => now()]);

        $this->deleteAccount()->assertRedirect(route('home'));

        foreach (['cart_items', 'wishlists', 'store_follows', 'buyer_addresses'] as $table) {
            $this->assertDatabaseMissing($table, ['buyer_id' => $this->buyer->id]);
        }
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->buyer->id, 'tokenable_type' => $this->buyer->getMorphClass()]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->buyer->id, 'notifiable_type' => $this->buyer->getMorphClass()]);
        $this->assertDatabaseMissing('password_reset_tokens_buyers', ['email' => 'maria.santos@example.com']);
    }

    public function test_the_id_document_is_deleted_from_storage(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('id.jpg')->store('buyer-ids', 'local');
        $this->buyer->forceFill(['upload_id_path' => $path])->save();

        $this->deleteAccount()->assertRedirect(route('home'));

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('', $this->tombstone()->upload_id_path);
    }

    public function test_admin_history_keeps_the_action_but_not_the_person(): void
    {
        $admin = Admin::factory()->create();
        $history = (new AccountActionHistory)->forceFill([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'account_type' => 'buyer',
            'account_id' => $this->buyer->id,
            'account_name' => 'Maria Santos',
            'account_email' => 'maria.santos@example.com',
            'action' => 'suspended',
            'from_status' => 'approved',
            'to_status' => 'suspended',
            'reason' => 'Policy check',
        ]);
        $history->save();

        $this->deleteAccount()->assertRedirect(route('home'));

        $history->refresh();
        $this->assertSame('Deleted buyer', $history->account_name);
        $this->assertSame('deleted-'.$this->buyer->id.'@archive.invalid', $history->account_email);
        $this->assertSame('suspended', $history->action);
        $this->assertSame('Policy check', $history->reason);
    }

    public function test_the_deleted_account_cannot_sign_in_and_its_email_can_register_again(): void
    {
        $this->deleteAccount()->assertRedirect(route('home'));
        auth('buyer')->logout();

        $this->post(route('unified.login'), ['email' => 'maria.santos@example.com', 'password' => self::PASSWORD]);
        $this->assertGuest('buyer');

        $this->assertNull(Buyer::query()->where('email', 'maria.santos@example.com')->first());
        $this->assertNull(Buyer::query()->find($this->buyer->id));

        $new = Buyer::factory()->create(['email' => 'maria.santos@example.com', 'contact_no' => '09171234567']);
        $this->assertNotSame($this->buyer->id, $new->id);
    }

    /** @return array<string, array{OrderStatus}> */
    public static function unfinishedStatuses(): array
    {
        return [
            'placed' => [OrderStatus::Placed],
            'out for delivery' => [OrderStatus::OutForDelivery],
            'delivered, not confirmed' => [OrderStatus::Delivered],
            'delivery failed, awaiting return' => [OrderStatus::DeliveryFailed],
        ];
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_an_order_in_progress_blocks_deletion(OrderStatus $status): void
    {
        $parcel = $this->parcel($status);

        $this->deleteAccount()
            ->assertRedirect(route('buyer.settings.index'))
            ->assertSessionHasErrors('account');

        $this->assertAuthenticatedAs($this->buyer, 'buyer');
        $this->assertSame('maria.santos@example.com', $this->buyer->fresh()->email);
        $this->assertSame('Maria Santos', $parcel->order->fresh()->shipping_address['name']);
    }

    /** @return array<string, array{OrderStatus}> */
    public static function finishedStatuses(): array
    {
        return [
            'completed' => [OrderStatus::Completed],
            'cancelled' => [OrderStatus::Cancelled],
            'returned' => [OrderStatus::Returned],
        ];
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_finished_order_does_not_block_deletion(OrderStatus $status): void
    {
        $this->parcel($status);

        $this->deleteAccount()->assertRedirect(route('home'));

        $this->assertNotNull($this->tombstone()->archived_at);
    }

    public function test_an_open_return_blocks_deletion(): void
    {
        $this->parcelReturn($this->parcel());

        $this->deleteAccount()->assertSessionHasErrors('account');

        $this->assertNull($this->tombstone()->archived_at);
    }

    public function test_a_refund_still_owed_blocks_deletion_until_it_is_recorded(): void
    {
        $return = $this->parcelReturn($this->parcel(), [
            'status' => ParcelReturnStatus::Completed->value,
            'financial_review_required' => true,
        ]);

        $this->deleteAccount()->assertSessionHasErrors('account');
        $this->assertNull($this->tombstone()->archived_at);

        $return->forceFill(['refunded_at' => now(), 'refund_reference' => 'GCASH-1'])->save();

        $this->deleteAccount()->assertRedirect(route('home'));
        $this->assertNotNull($this->tombstone()->archived_at);
    }

    public function test_the_settings_page_explains_a_block_and_hides_the_button(): void
    {
        $this->parcel(OrderStatus::Placed);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.settings.index'))->assertOk()
            ->assertSee('You have an order in progress.')
            ->assertDontSee("document.getElementById('deleteAccountModal').showModal()", false);
    }

    public function test_the_dialog_describes_what_is_kept(): void
    {
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.settings.index'))->assertOk()
            ->assertSee('Order, payment and delivery records are kept')
            ->assertDontSee('including orders');
    }

    public function test_a_wrong_password_changes_nothing_and_reopens_the_dialog(): void
    {
        $this->deleteAccount(['password' => 'Wrong-Pass9!'])
            ->assertRedirect(route('buyer.settings.index'))
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');

        $this->assertSame('maria.santos@example.com', $this->buyer->fresh()->email);

        $this->followingRedirects();
        $this->deleteAccount(['password' => 'Wrong-Pass9!'])->assertOk()
            ->assertSee('That password doesn', false)
            ->assertSee("<script>document.getElementById('deleteAccountModal').showModal();</script>", false);
    }

    public function test_the_permanent_checkbox_is_required_by_the_server(): void
    {
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.settings.deleteAccount'), ['password' => self::PASSWORD])
            ->assertSessionHasErrors('confirm');

        $this->assertNull($this->tombstone()->archived_at);
    }

    public function test_a_google_account_confirms_with_its_email(): void
    {
        $this->buyer->forceFill(['password' => null])->save();

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.settings.index'))->assertSee('Type your email to confirm');

        $this->deleteAccount(['password' => null, 'confirm_email' => 'someone@example.com'])->assertSessionHasErrors('confirm_email');
        $this->assertNull($this->tombstone()->archived_at);

        $this->deleteAccount(['password' => null, 'confirm_email' => 'Maria.Santos@example.com'])->assertRedirect(route('home'));
        $this->assertNotNull($this->tombstone()->archived_at);
    }

    public function test_guests_cannot_delete_an_account(): void
    {
        $this->post(route('buyer.settings.deleteAccount'), ['password' => self::PASSWORD, 'confirm' => '1'])->assertRedirect();

        $this->assertNull($this->tombstone()->archived_at);
    }

    public function test_pages_that_show_the_buyer_keep_working_after_deletion(): void
    {
        $parcel = $this->parcel();
        $seller = $parcel->seller;
        $seller->forceFill(['status' => 'approved'])->save();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        Review::query()->forceCreate([
            'buyer_id' => $this->buyer->id,
            'product_id' => $product->id,
            'order_item_id' => OrderItem::factory()->create(['seller_order_id' => $parcel->id, 'product_id' => $product->id])->id,
            'rating' => 5,
            'body' => 'Lovely quality.',
        ]);
        $conversation = Conversation::query()->forceCreate(['buyer_id' => $this->buyer->id, 'seller_id' => $seller->id]);

        $this->deleteAccount()->assertRedirect(route('home'));
        auth('buyer')->logout();

        $this->actingAs($seller, 'seller');
        $this->get(route('seller.orders.show', $parcel))->assertOk()
            ->assertSee('Deleted buyer')
            ->assertDontSee('Mabini Street');
        $this->get(route('seller.orders.index'))->assertOk()->assertDontSee('Maria');
        $this->get(route('seller.messages.index'))->assertOk()->assertDontSee('Maria');
        $this->get(route('seller.messages.show', $conversation))->assertOk()->assertDontSee('Maria');
        $this->getJson(route('seller.messages.show', $conversation))->assertOk()->assertJsonPath('buyer_name', 'Buyer');

        auth('seller')->logout();

        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('Verified buyer')
            ->assertDontSee('Maria');
    }
}

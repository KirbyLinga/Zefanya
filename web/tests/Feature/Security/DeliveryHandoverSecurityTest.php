<?php

namespace Tests\Feature\Security;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H1 fail-closed delivery handover.
 *
 * The old rules let a null OTP hash through and never required a photo. These
 * tests assert the new contract: a server-issued, expiring, single-use code or
 * a signed token bound to that code, plus a proof photo, on every delivery.
 */
class DeliveryHandoverSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function buyer(): Buyer
    {
        return Buyer::factory()->create();
    }

    private function courier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function job(
        Buyer $buyer,
        LogisticsProvider $courier,
        string $status = 'OUT_FOR_DELIVERY',
        array $attributes = [],
    ): SellerOrder {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'name' => 'Security Test Parcel',
        ]);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['name' => $buyer->fullName(), 'full' => 'Test address'],
            'subtotal_minor' => 50000,
            'discount_minor' => 0,
            'total_minor' => 50000,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => 50000,
            'assigned_courier_id' => $courier->id,
            'delivery_fee_minor' => 8500,
            'delivery_area' => 'Test area',
        ]);

        foreach ($attributes as $column => $value) {
            $sellerOrder->{$column} = $value;
        }

        $sellerOrder->forceFill(['status' => $status])->save();

        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'price_minor' => 50000,
        ]);

        return $sellerOrder->fresh();
    }

    private function issuePass(Buyer $buyer, SellerOrder $job): array
    {
        Sanctum::actingAs($buyer);

        return $this->postJson(route('api.orders.delivery-pass', $job))
            ->assertOk()
            ->json('data');
    }

    private function deliver(SellerOrder $job, array $payload, bool $withPhoto = true)
    {
        if ($withPhoto) {
            $payload['proof_photo'] = UploadedFile::fake()->create('doorstep.jpg', 100, 'image/jpeg');
        }

        return $this->patch(
            route('api.courier.jobs.milestone', $job),
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    public function test_delivery_without_any_code_or_hash_is_refused(): void
    {
        $courier = $this->courier();
        $job = $this->job($this->buyer(), $courier);

        Sanctum::actingAs($courier);

        $this->deliver($job, ['milestone' => 'DELIVERED'])
            ->assertStatus(422);

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_null_hash_never_allows_delivery(): void
    {
        $courier = $this->courier();
        $job = $this->job($this->buyer(), $courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => null,
        ]);

        Sanctum::actingAs($courier);

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => '123456'])
            ->assertStatus(422);

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_a_buyer_can_request_a_code_and_signed_token(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $job = $this->job($buyer, $this->courier());

        $pass = $this->issuePass($buyer, $job);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pass['code']);
        $this->assertNotEmpty($pass['token']);
        $this->assertNotSame($pass['code'], $pass['token']);
        $this->assertStringNotContainsString($pass['code'], $pass['token']);
        $this->assertNotNull($job->fresh()->delivery_otp_expires_at);
        $this->assertNotNull($job->fresh()->delivery_otp_hash);
    }

    public function test_requesting_again_invalidates_the_previous_code_and_token(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);

        $first = $this->issuePass($buyer, $job);
        $second = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);

        $this->deliver($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => $first['code'],
        ])->assertStatus(422);

        $this->deliver($job, [
            'milestone' => 'DELIVERED',
            'delivery_token' => $first['token'],
        ])->assertStatus(422);

        $this->deliver($job, [
            'milestone' => 'DELIVERED',
            'delivery_token' => $second['token'],
        ])->assertOk()->assertJsonPath('milestone', 'DELIVERED');
    }

    public function test_expired_code_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        $this->travel(31)->minutes();
        Sanctum::actingAs($courier);

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertStatus(422);
    }

    public function test_reused_code_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertOk();

        // Re-open a fresh parcel with the same old hash: the used_at marker
        // must survive a status reset, so a replay cannot succeed.
        $job->forceFill(['status' => OrderStatus::OutForDelivery])->save();

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertStatus(422);
    }

    public function test_stale_token_version_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        // Reissue: same code maybe, new hash and version. The old token is stale.
        $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);
        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_token' => $pass['token']])
            ->assertStatus(422);
    }

    public function test_tampered_token_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        $token = $pass['token'];
        $token[0] = $token[0] === 'a' ? 'b' : 'a';

        Sanctum::actingAs($courier);
        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_token' => $token])
            ->assertStatus(422);
    }

    public function test_token_for_another_order_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $other = $this->job($buyer, $courier);

        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);
        $this->deliver($other, ['milestone' => 'DELIVERED', 'delivery_token' => $pass['token']])
            ->assertStatus(422);
    }

    public function test_a_different_courier_cannot_confirm(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $intruder = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($intruder);
        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertForbidden();
    }

    public function test_a_buyer_cannot_confirm_delivery(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $job = $this->job($buyer, $this->courier());
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($buyer);
        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertForbidden();
    }

    public function test_five_wrong_codes_lock_then_unlock_after_fifteen_minutes(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);

        for ($i = 0; $i < 5; $i++) {
            $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => '000000'])
                ->assertStatus(422);
        }

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertStatus(423);

        $this->travel(15)->minutes();

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']])
            ->assertOk();
    }

    public function test_replayed_token_is_refused(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);
        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_token' => $pass['token']])
            ->assertOk();

        $job->forceFill(['status' => OrderStatus::OutForDelivery])->save();

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_token' => $pass['token']])
            ->assertStatus(422);
    }

    public function test_success_requires_and_stores_a_proof_photo(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);

        Sanctum::actingAs($courier);

        $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']], false)
            ->assertStatus(422);

        $response = $this->deliver($job, ['milestone' => 'DELIVERED', 'delivery_otp' => $pass['code']]);
        $response->assertOk()->assertJsonPath('milestone', 'DELIVERED');

        $fresh = $job->fresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNotNull($fresh->delivery_otp_used_at);
        $this->assertNotNull($fresh->delivery_proof_path);
        Storage::disk('local')->assertExists($fresh->delivery_proof_path);
    }

    public function test_admin_override_delivers_with_a_reason_photo_and_audit_row(): void
    {
        Storage::fake('local');
        $admin = Admin::create([
            'name' => 'Override Admin',
            'email' => 'override@zefanya.local',
            'password' => 'AdminSecret123!',
        ]);
        $admin->forceFill(['role' => 'admin'])->save();
        $job = $this->job($this->buyer(), $this->courier());

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.orders.deliver-override', $job), [
                'reason' => 'Buyer lost the phone; identity confirmed at the door.',
                'proof_photo' => UploadedFile::fake()->create('doorstep.jpg', 100, 'image/jpeg'),
            ])
            ->assertOk()
            ->assertJsonPath('milestone', 'DELIVERED');

        $fresh = $job->fresh();
        $this->assertSame(OrderStatus::Delivered, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNotNull($fresh->delivery_proof_path);

        $audit = DB::table('delivery_handover_audits')
            ->where('seller_order_id', $job->id)
            ->where('action', 'admin_override')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame($admin->id, (int) $audit->actor_id);
        $this->assertStringContainsString('Buyer lost the phone', (string) $audit->metadata);
    }

    public function test_admin_override_requires_a_reason_and_proof_photo(): void
    {
        Storage::fake('local');
        $admin = Admin::create([
            'name' => 'Override Admin',
            'email' => 'override2@zefanya.local',
            'password' => 'AdminSecret123!',
        ]);
        $admin->forceFill(['role' => 'admin'])->save();
        $job = $this->job($this->buyer(), $this->courier());

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.orders.deliver-override', $job), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'proof_photo']);

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_a_non_admin_role_cannot_override_a_delivery(): void
    {
        Storage::fake('local');
        $support = Admin::create([
            'name' => 'Support Agent',
            'email' => 'support@zefanya.local',
            'password' => 'AdminSecret123!',
        ]);
        $support->forceFill(['role' => 'support'])->save();
        $job = $this->job($this->buyer(), $this->courier());

        $this->actingAs($support, 'admin')
            ->postJson(route('admin.orders.deliver-override', $job), [
                'reason' => 'Should not work.',
                'proof_photo' => UploadedFile::fake()->create('doorstep.jpg', 100, 'image/jpeg'),
            ])
            ->assertForbidden();

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_a_courier_token_cannot_call_the_admin_override(): void
    {
        $courier = $this->courier();
        $job = $this->job($this->buyer(), $courier);

        Sanctum::actingAs($courier);

        $this->postJson(route('admin.orders.deliver-override', $job), [
            'reason' => 'Not an administrator.',
            'proof_photo' => UploadedFile::fake()->create('doorstep.jpg', 100, 'image/jpeg'),
        ])->assertUnauthorized();

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_cod_still_requires_the_code_and_photo(): void
    {
        Storage::fake('local');
        $buyer = $this->buyer();
        $courier = $this->courier();
        $job = $this->job($buyer, $courier);
        $pass = $this->issuePass($buyer, $job);
        $payment = Payment::query()->create([
            'seller_order_id' => $job->id,
            'amount_minor' => 70000,
        ]);

        Sanctum::actingAs($courier);

        $this->deliver($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => $pass['code'],
            'cod_amount' => 700.00,
        ], false)->assertStatus(422);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $this->deliver($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => $pass['code'],
            'cod_amount' => 700.00,
        ])->assertOk()->assertJsonPath('payment_status', 'paid');
    }

    /**
     * Enumerates every application path that writes the delivered status.
     * If a new writer appears, this fails and forces it through the service.
     */
    public function test_only_the_delivery_service_writes_the_delivered_status(): void
    {
        $writers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (preg_match_all('/OrderStatus::Delivered|[\'"]DELIVERED[\'"]\s*\]?\s*\)?\s*;/', $contents)) {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $writers[] = str_replace('\\', '/', $relative);
            }
        }

        sort($writers);

        $this->assertSame([
            'app/Enums/OrderStatus.php',
            'app/Services/OrderDeliveryService.php',
        ], array_values(array_unique($writers)));
    }
}

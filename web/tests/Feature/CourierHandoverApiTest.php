<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The delivery handover rules behind PATCH /api/courier/jobs/{job}/milestone:
 *
 *   - pickup checks the typed waybill
 *   - a failed attempt needs a reason, counts, and returns after three
 *   - buyer refusal ends the chain immediately
 *   - DELIVERED verifies the buyer's code, locks after five wrong ones
 *   - COD must match to the centavo before the payment is settled
 *   - the job resource exposes the handover facts but never the code
 *
 * These are server rules, not display rules: every test asserts stored state
 * after a request the app could actually send.
 */
class CourierHandoverApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function courier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  Stored directly, including
     *                                            non-fillable handover columns.
     */
    private function assignJob(
        LogisticsProvider $courier,
        string $status = 'OUT_FOR_DELIVERY',
        array $attributes = [],
    ): SellerOrder {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'name' => 'Linen Blend Wrap Dress',
        ]);

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['name' => 'Marites Santos', 'full' => 'Test address'],
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

    private function payment(SellerOrder $job, int $amountMinor = 70000): Payment
    {
        return Payment::query()->create([
            'seller_order_id' => $job->id,
            'amount_minor' => $amountMinor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function advance(SellerOrder $job, array $body)
    {
        return $this->patchJson(
            route('api.courier.jobs.milestone', $job),
            $body,
        );
    }

    // ── Job resource ─────────────────────────────────────────────────────────

    public function test_job_resource_exposes_handover_facts_but_never_the_code(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'tracking_number' => 'ZEF-RID-777',
            'delivery_attempts' => 1,
            'failure_reason' => 'Buyer not available',
            'delivery_otp_hash' => Hash::make('482913'),
        ]);
        $this->payment($job, 70000);

        Sanctum::actingAs($courier);

        $response = $this->getJson(route('api.courier.jobs.index'))->assertOk();

        $response->assertJsonPath('data.0.tracking_number', 'ZEF-RID-777');
        $response->assertJsonPath('data.0.payment_method', 'cod');
        $response->assertJsonPath('data.0.payment_status', 'pending');
        $response->assertJsonPath('data.0.delivery_otp_required', true);
        $response->assertJsonPath('data.0.delivery_attempts', 1);
        $response->assertJsonPath('data.0.failure_reason', 'Buyer not available');
        $this->assertSame(700.0, (float) $response->json('data.0.cod_amount'));

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('482913', $payload);
        $this->assertStringNotContainsString('delivery_otp_hash', $payload);
    }

    // ── Pickup ───────────────────────────────────────────────────────────────

    public function test_pickup_requires_the_matching_tracking_number(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'READY_FOR_PICKUP', [
            'tracking_number' => 'ZEF-RID-777',
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'PICKED_UP'])
            ->assertStatus(422);
        $this->advance($job, [
            'milestone' => 'PICKED_UP',
            'tracking_number' => 'ZEF-WRONG-99',
        ])->assertStatus(422);

        $this->assertSame('READY_FOR_PICKUP', $job->fresh()->status->value);

        $this->advance($job, [
            'milestone' => 'PICKED_UP',
            'tracking_number' => ' zef-rid-777 ',
        ])->assertOk()->assertJsonPath('milestone', 'PICKED_UP');
    }

    public function test_pickup_without_an_issued_tracking_number_is_allowed(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'READY_FOR_PICKUP');

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'PICKED_UP'])
            ->assertOk()
            ->assertJsonPath('milestone', 'PICKED_UP');
    }

    // ── Failed attempts ──────────────────────────────────────────────────────

    public function test_a_failure_without_a_reason_changes_nothing(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'DELIVERY_FAILED'])
            ->assertStatus(422);

        $fresh = $job->fresh();
        $this->assertSame('OUT_FOR_DELIVERY', $fresh->status->value);
        $this->assertSame(0, $fresh->delivery_attempts);
        $this->assertNull($fresh->failure_reason);
    }

    public function test_a_failed_attempt_increments_and_keeps_its_reason(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERY_FAILED',
            'failure_reason' => 'Buyer not available',
        ])->assertOk()
            ->assertJsonPath('milestone', 'DELIVERY_FAILED')
            ->assertJsonPath('delivery_attempts', 1);

        $fresh = $job->fresh();
        $this->assertSame(1, $fresh->delivery_attempts);
        $this->assertSame('Buyer not available', $fresh->failure_reason);
    }

    public function test_a_failed_attempt_can_be_retried(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'DELIVERY_FAILED', [
            'delivery_attempts' => 1,
            'failure_reason' => 'Buyer not available',
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'OUT_FOR_DELIVERY'])
            ->assertOk()
            ->assertJsonPath('milestone', 'OUT_FOR_DELIVERY');

        $this->assertSame(1, $job->fresh()->delivery_attempts);
    }

    public function test_the_third_failure_returns_the_parcel(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_attempts' => 2,
            'failure_reason' => 'Wrong address',
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERY_FAILED',
            'failure_reason' => 'Buyer not available',
        ])->assertOk()
            ->assertJsonPath('milestone', 'RETURNED')
            ->assertJsonPath('delivery_attempts', 3);

        $fresh = $job->fresh();
        $this->assertSame(OrderStatus::Returned, $fresh->status);
        $this->assertSame(3, $fresh->delivery_attempts);
    }

    public function test_the_third_failure_reported_as_returned_is_accepted(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_attempts' => 2,
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'RETURNED',
            'failure_reason' => 'Buyer not available',
        ])->assertOk()
            ->assertJsonPath('milestone', 'RETURNED')
            ->assertJsonPath('delivery_attempts', 3);
    }

    public function test_an_early_return_without_refusal_is_refused(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'RETURNED',
            'failure_reason' => 'Wrong address',
        ])->assertStatus(422);

        $fresh = $job->fresh();
        $this->assertSame('OUT_FOR_DELIVERY', $fresh->status->value);
        $this->assertSame(0, $fresh->delivery_attempts);
    }

    public function test_buyer_refusal_returns_immediately(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'RETURNED',
            'failure_reason' => 'Buyer refused',
        ])->assertOk()
            ->assertJsonPath('milestone', 'RETURNED')
            ->assertJsonPath('delivery_attempts', 0);
    }

    public function test_a_returned_job_cannot_be_delivered_again(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'RETURNED', ['delivery_attempts' => 3]);

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'OUT_FOR_DELIVERY'])
            ->assertStatus(422);
        $this->advance($job, ['milestone' => 'DELIVERED'])
            ->assertStatus(422);
    }

    // ── Delivery OTP ─────────────────────────────────────────────────────────

    public function test_delivery_requires_the_buyers_code(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'DELIVERED'])
            ->assertStatus(422);

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
        $this->assertSame(0, $job->fresh()->delivery_otp_attempts);
    }

    public function test_a_five_digit_code_is_a_format_error_not_a_wrong_code(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '48291',
        ])->assertStatus(422);

        $this->assertSame(0, $job->fresh()->delivery_otp_attempts);
    }

    public function test_a_wrong_code_counts_toward_the_lock(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '000000',
        ])->assertStatus(422);

        $fresh = $job->fresh();
        $this->assertSame(1, $fresh->delivery_otp_attempts);
        $this->assertNull($fresh->delivery_otp_locked_until);
        $this->assertSame('OUT_FOR_DELIVERY', $fresh->status->value);
    }

    public function test_five_wrong_codes_lock_entry_for_fifteen_minutes(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
        ]);

        Sanctum::actingAs($courier);

        for ($i = 0; $i < 5; $i++) {
            $this->advance($job, [
                'milestone' => 'DELIVERED',
                'delivery_otp' => '000000',
            ])->assertStatus(422);
        }

        $fresh = $job->fresh();
        $this->assertSame(5, $fresh->delivery_otp_attempts);
        $this->assertNotNull($fresh->delivery_otp_locked_until);

        // Even the correct code is refused while the lock is on.
        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '482913',
        ])->assertStatus(423);

        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_the_code_works_again_exactly_when_the_lock_expires(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
            'delivery_otp_attempts' => 5,
            'delivery_otp_locked_until' => now()->addMinutes(15),
        ]);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '482913',
        ])->assertStatus(423);

        $this->travel(15)->minutes();

        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '482913',
        ])->assertOk()
            ->assertJsonPath('milestone', 'DELIVERED');

        $fresh = $job->fresh();
        $this->assertSame(0, $fresh->delivery_otp_attempts);
        $this->assertNull($fresh->delivery_otp_locked_until);
        $this->assertNotNull($fresh->delivered_at);
    }

    // ── COD ──────────────────────────────────────────────────────────────────

    public function test_cod_must_be_entered_before_the_parcel_is_delivered(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        $payment = $this->payment($job, 70000);

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'DELIVERED'])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_cod_one_centavo_off_is_refused(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        $payment = $this->payment($job, 70000);

        Sanctum::actingAs($courier);

        foreach ([699.99, 700.01] as $wrong) {
            $this->advance($job, [
                'milestone' => 'DELIVERED',
                'cod_amount' => $wrong,
            ])->assertStatus(422);
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame('OUT_FOR_DELIVERY', $job->fresh()->status->value);
    }

    public function test_an_exact_cod_amount_settles_the_payment_and_records_delivery(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        $payment = $this->payment($job, 70000);

        Sanctum::actingAs($courier);

        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'cod_amount' => 700.00,
        ])->assertOk()
            ->assertJsonPath('milestone', 'DELIVERED')
            ->assertJsonPath('payment_status', 'paid');

        $freshPayment = $payment->fresh();
        $this->assertSame(PaymentStatus::Paid, $freshPayment->status);
        $this->assertNotNull($freshPayment->paid_at);

        $freshJob = $job->fresh();
        $this->assertSame(OrderStatus::Delivered, $freshJob->status);
        $this->assertNotNull($freshJob->delivered_at);
    }

    public function test_a_paid_order_needs_no_cash_at_the_door(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY');
        $payment = $this->payment($job, 70000);
        $payment->markPaid();

        Sanctum::actingAs($courier);

        $this->advance($job, ['milestone' => 'DELIVERED'])
            ->assertOk()
            ->assertJsonPath('payment_status', 'paid');
    }

    public function test_the_otp_and_cod_are_checked_together(): void
    {
        $courier = $this->courier();
        $job = $this->assignJob($courier, 'OUT_FOR_DELIVERY', [
            'delivery_otp_hash' => Hash::make('482913'),
        ]);
        $payment = $this->payment($job, 70000);

        Sanctum::actingAs($courier);

        // Right code, wrong cash: still not delivered, and payment stays open.
        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '482913',
            'cod_amount' => 699.00,
        ])->assertStatus(422);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        // Both right: one transaction settles both facts.
        $this->advance($job, [
            'milestone' => 'DELIVERED',
            'delivery_otp' => '482913',
            'cod_amount' => 700.00,
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $job->fresh()->status);
    }
}

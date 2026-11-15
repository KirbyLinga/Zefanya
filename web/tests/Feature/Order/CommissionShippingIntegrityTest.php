<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\ProviderAllocationService;
use App\Services\Shared\CommissionFinalizationService;
use App\Services\Shared\VoucherFundingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CommissionShippingIntegrityTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['buyer.shipping_rates.same_municipality.first_kg_minor' => 5000]);
    }

    private function checkout(array $prices = ['100.00', '200.00'], array $extra = [], bool $voucher = true, string $slot = 'platform', string $funding = Voucher::FUNDING_MARKETPLACE, bool $shippingError = false): array
    {
        $buyer = Buyer::factory()->create();
        $sellers = collect($prices)->map(fn () => Seller::factory()->create(['status' => 'approved']));
        $this->bootstrapCheckoutCarriers($buyer, $sellers);
        $lines = $sellers->map(function (Seller $seller, int $i) use ($prices, $buyer) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => $prices[$i], 'stock_quantity' => 10]);

            return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        });
        $discount = $voucher ? ($slot === 'shipping' ? Voucher::factory()->shipping()->fixed(5000) : Voucher::factory()->platform()->fixed(300))
            ->create(['funding_source' => $funding]) : null;
        $token = CheckoutToken::issue($buyer->id);
        $response = $this->actingAs($buyer, 'buyer')->withSession([
            'checkout_item_ids' => $lines->pluck('id')->all(),
            'cart_vouchers' => $discount ? [$slot => $discount->code] : [],
        ])->post(route('buyer.checkout.store'), array_replace([
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload($sellers),
        ], $extra))->assertRedirect();
        if ($shippingError) {
            $response->assertSessionHasErrors('shipping');

            return [null, $buyer, $discount];
        }
        $response->assertSessionHasNoErrors();

        return [Order::query()->where('buyer_id', $buyer->id)->sole(), $buyer, $discount];
    }

    private function remit(SellerOrder $order): SellerSettlement
    {
        $payment = $order->payment;
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => $payment->amount_minor])->save();
        $courier = Courier::factory()->create(['logistics_provider_id' => $order->carrier_provider_id]);
        $cod = new CodRemittance;
        $cod->forceFill(['payment_id' => $payment->id, 'seller_order_id' => $order->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'courier_id' => $courier->id,
            'amount_minor' => $payment->amount_minor, 'status' => CodRemittance::PENDING_REMITTANCE]);
        $cod->snapshotAllocation();
        $cod->save();
        app(CodRemittanceService::class)->confirmReceipt($cod, $courier->provider, $payment->amount_minor);

        return SellerSettlement::query()->where('seller_order_id', $order->id)->sole();
    }

    public function test_real_multi_seller_checkout_finalizes_each_discount_share_without_blocking_settlement(): void
    {
        [$parent] = $this->checkout();
        $this->assertSame(300, $parent->discount_minor);
        foreach ($parent->sellerOrders()->orderBy('seller_id')->get() as $order) {
            $allocation = app(VoucherFundingService::class)->allocationFor($order);
            $this->assertNull($allocation['pending_reason']);
            $this->assertSame($order->platform_voucher_discount_minor, $allocation['marketplace_minor']);
            $order->forceFill(['status' => OrderStatus::Completed])->save();
            $final = app(CommissionFinalizationService::class)->finalize($order);
            $this->assertSame(1000, $final->commission_bps);
            $this->assertSame(intdiv($order->subtotal_minor, 10), $final->commission_minor);
            $this->assertSame($order->subtotal_minor - $final->commission_minor, $final->seller_net_minor);
            $settlement = $this->remit($final);
            $this->assertNull($settlement->pending_reason);
            $this->assertSame($final->seller_net_minor, $settlement->seller_payout_minor);
        }
        $this->assertSame(3000, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
        $this->assertSame(300, app(PlatformFinanceService::class)->earnings(false)['platformVoucherMinor']);
        $this->assertSame(2700, app(PlatformFinanceService::class)->earnings(false)['netMinor']);
    }

    public function test_corrupt_parent_discount_conservation_keeps_multi_seller_funding_on_hold(): void
    {
        [$parent] = $this->checkout();
        $parent->forceFill(['discount_minor' => 299])->save();
        foreach ($parent->sellerOrders as $order) {
            $this->assertSame(SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED, app(VoucherFundingService::class)->allocationFor($order)['pending_reason']);
        }
    }

    public function test_cancelled_sibling_keeps_its_original_discount_in_checkout_conservation(): void
    {
        [$parent] = $this->checkout();
        $orders = $parent->sellerOrders()->orderBy('id')->get();
        $orders[1]->forceFill(['status' => OrderStatus::Cancelled])->save();
        $allocation = app(VoucherFundingService::class)->allocationFor($orders[0]);
        $this->assertNull($allocation['pending_reason']);
        $this->assertSame(100, $allocation['marketplace_minor']);
        $this->assertSame(300, $parent->fresh()->discount_minor);
    }

    public function test_multiple_seller_funded_shop_and_marketplace_shipping_shares_reconcile(): void
    {
        $parent = Order::factory()->create(['discount_minor' => 3000]);
        $orders = collect([10000, 20000])->map(function (int $subtotal, int $i) use ($parent) {
            $shop = ($i + 1) * 1000;
            $shipping = ($i + 1) * 500;
            $order = SellerOrder::factory()->status(OrderStatus::Completed)->create(['order_id' => $parent->id, 'subtotal_minor' => $subtotal]);
            $order->forceFill(['shipping_fee_minor' => 5000, 'voucher_discount_minor' => $shop,
                'shop_voucher_discount_minor' => $shop, 'shop_voucher_funding_source' => Voucher::FUNDING_SELLER,
                'platform_voucher_discount_minor' => 0, 'shipping_discount_minor' => $shipping,
                'shipping_voucher_discount_minor' => $shipping, 'shipping_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
                'seller_funded_discount_minor' => $shop, 'marketplace_funded_discount_minor' => $shipping])->save();

            return $order;
        })->map(fn (SellerOrder $order) => app(CommissionFinalizationService::class)->finalize($order));
        $this->assertSame([900, 1800], $orders->pluck('commission_minor')->all());
        $this->assertSame([8100, 16200], $orders->pluck('seller_net_minor')->all());
        foreach ($orders as $order) {
            $this->assertNull(app(VoucherFundingService::class)->allocationFor($order)['pending_reason']);
        }
    }

    public function test_unsupported_shipping_subsidy_never_invents_collected_cash_or_releases_a_settlement(): void
    {
        // Characterize an existing funding gap; no provider subsidy policy is invented.
        [$parent] = $this->checkout(['10.00'], slot: 'shipping');
        $order = $parent->sellerOrders()->sole();
        $this->assertSame(5000, $order->shipping_fee_minor);
        $this->assertSame(5000, $order->shipping_discount_minor);
        $this->assertSame(1000, $order->payment->amount_minor);
        try {
            $this->remit($order);
            $this->fail('The workflow invented enough collected cash to retain the whole shipping fee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }
        $this->assertSame(0, SellerSettlement::query()->count());
        $this->assertSame(1000, $order->payment->fresh()->collected_amount_minor);
        $this->assertSame(CodRemittance::PENDING_REMITTANCE, CodRemittance::query()->sole()->status);
    }

    public function test_existing_seller_funded_shipping_commission_basis_is_not_silently_repriced(): void
    {
        [$parent] = $this->checkout(['100.00'], slot: 'shipping', funding: Voucher::FUNDING_SELLER);
        $order = $parent->sellerOrders()->sole();
        $order->forceFill(['status' => OrderStatus::Completed])->save();
        $final = app(CommissionFinalizationService::class)->finalize($order);
        // Existing policy includes seller-funded shipping discounts in seller costs.
        $this->assertSame(1000, $final->commission_bps);
        $this->assertSame(5000, $final->seller_funded_discount_minor);
        $this->assertSame(500, $final->commission_minor);
        $this->assertSame(4500, $final->seller_net_minor);
    }

    #[TestWith([0])]
    #[TestWith([100])]
    #[TestWith([500])]
    #[TestWith([1000])]
    #[TestWith([5000])]
    #[TestWith([10000])]
    #[TestWith([-100])]
    #[TestWith([10001])]
    public function test_checkout_ignores_forged_commission_shipping_and_allocation_fields(int $bps): void
    {
        [$parent] = $this->checkout(['100.00'], [
            'commission_bps' => $bps, 'commission_minor' => 0, 'seller_net_minor' => 999999,
            'subtotal_minor' => 1, 'shipping_fee_minor' => -1, 'shipping_discount_minor' => 999999,
            'distance' => 0, 'origin_provider_id' => 999999,
            'destination_provider_id' => 999999, 'carrier_provider_id' => 999999,
        ], false);
        $order = $parent->sellerOrders()->sole();
        $this->assertNull($order->commission_bps);
        $this->assertNull($order->commission_minor);
        $this->assertSame(10000, $order->subtotal_minor);
        $this->assertSame(5000, $order->shipping_fee_minor);
        $this->assertSame(0, $order->shipping_discount_minor);
        $this->assertSame($this->checkoutCarrier->id, $order->carrier_provider_id);
        $this->assertSame(15000, $order->payment->amount_minor);
        $order->forceFill(['status' => OrderStatus::Completed])->save();
        $final = app(CommissionFinalizationService::class)->finalize($order);
        $this->assertSame(1000, $final->commission_bps);
        $this->assertSame(1000, $final->commission_minor);
        $this->assertSame(9000, $final->seller_net_minor);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([100])]
    #[TestWith([999999999])]
    public function test_submitted_shipping_quotes_are_rejected_without_financial_writes(int $fee): void
    {
        $this->checkout(['100.00'], ['shipping_fees' => [$fee]], voucher: false, shippingError: true);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CheckoutToken::query()->where('used', true)->count());
    }

    #[TestWith(['seller'])]
    #[TestWith(['rider'])]
    #[TestWith(['logistics'])]
    #[TestWith(['admin'])]
    public function test_other_roles_cannot_submit_checkout_financial_overrides(string $guard): void
    {
        $actor = match ($guard) {
            'seller' => Seller::factory()->create(['status' => 'approved']),
            'rider' => Rider::factory()->create(['status' => Rider::STATUS_APPROVED]),
            'logistics' => LogisticsProvider::factory()->create(['status' => 'approved']),
            'admin' => Admin::factory()->create(['role' => 'moderator']),
        };
        $this->actingAs($actor, $guard)->postJson(route('buyer.checkout.store'), [
            'commission_bps' => 0, 'shipping_fee_minor' => 0, 'seller_net_minor' => 999999,
        ])->assertUnauthorized();
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    #[TestWith([1, 0, 0])]
    #[TestWith([5, 1, 2])]
    #[TestWith([10, 1, 4])]
    #[TestWith([9999, 1000, 4000])]
    #[TestWith([99999, 10000, 40000])]
    #[TestWith([1000000, 100000, 400000])]
    #[TestWith([100000000, 10000000, 40000000])]
    public function test_required_money_boundaries_reconcile_commission_and_cross_provider_shipping(int $minor, int $commission, int $originShare): void
    {
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'order_id' => Order::factory()->create(['discount_minor' => 0])->id,
            'subtotal_minor' => $minor, 'shipping_fee_minor' => $minor,
        ]);
        $final = app(CommissionFinalizationService::class)->finalize($order);
        $this->assertSame($commission, $final->commission_minor);
        $this->assertSame($minor, $final->commission_minor + $final->seller_net_minor);
        $origin = LogisticsProvider::factory()->create();
        $order->forceFill(['carrier_provider_id' => $origin->id])->save();
        $cod = (new CodRemittance)->forceFill(['logistics_provider_id' => LogisticsProvider::factory()->create()->id]);
        $cod->setRelation('sellerOrder', $order);
        $cod->snapshotAllocation();
        $this->assertSame($originShare, $cod->originShareMinor());
        $this->assertSame($minor, $cod->originShareMinor() + $cod->keptShippingMinor());
        $this->assertSame(Money::percentage($minor, 40), $cod->originShareMinor());
    }

    public function test_saved_five_percent_finalization_is_idempotent_and_reports_the_saved_rate(): void
    {
        $historical = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'order_id' => Order::factory()->create(['discount_minor' => 0])->id, 'subtotal_minor' => 10000,
        ]);
        $historical->forceFill(['commission_bps' => 500, 'commission_minor' => 500, 'seller_net_minor' => 9500, 'commission_finalized_at' => now()->subDay()])->save();
        $before = $historical->fresh()->getRawOriginal();
        $finalizer = app(CommissionFinalizationService::class);
        $finalizer->finalize($historical);
        $finalizer->finalize($historical);
        $this->assertSame($before, $historical->fresh()->getRawOriginal());
        $this->assertSame(500, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    #[TestWith(['DELIVERED'])]
    #[TestWith(['CANCELLED'])]
    public function test_non_completed_order_cannot_recognize_commission(string $status): void
    {
        $order = SellerOrder::factory()->create(['status' => $status]);
        try {
            app(CommissionFinalizationService::class)->finalize($order);
            $this->fail('A non-completed order was finalized.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('completed', $exception->getMessage());
        }
        $this->assertNull($order->fresh()->commission_finalized_at);
        $this->assertSame(0, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    public function test_failed_settlement_sync_rolls_back_new_commission_recognition(): void
    {
        [$parent] = $this->checkout(['100.00'], voucher: false);
        $order = $parent->sellerOrders()->sole();
        $settlement = $this->remit($order);
        $order->forceFill(['status' => OrderStatus::Completed])->save();
        $before = $order->fresh()->getRawOriginal();
        $settlementBefore = $settlement->fresh()->getRawOriginal();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'update "seller_settlements"')) {
                $armed = false;
                throw new \RuntimeException('Controlled commission settlement failure.');
            }
        });
        try {
            app(CommissionFinalizationService::class)->finalize($order);
            $this->fail('Settlement failure did not interrupt finalization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled commission settlement failure.', $exception->getMessage());
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame($settlementBefore, $settlement->fresh()->getRawOriginal());
        $this->assertSame(0, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    public function test_partial_financial_snapshots_are_not_treated_as_valid_pending_rates(): void
    {
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create(['subtotal_minor' => 10000]);
        $order->forceFill(['commission_bps' => 500, 'commission_minor' => 500])->save();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Partial commission records');
        app(CommissionFinalizationService::class)->finalize($order);
    }

    public function test_delivery_caller_transaction_rolls_back_a_partially_prepared_provider_chain(): void
    {
        $order = SellerOrder::factory()->create();
        $ids = LogisticsProvider::factory()->count(3)->create()->pluck('id')->all();
        $service = new class($ids) extends ProviderAllocationService
        {
            public function __construct(private array $ids) {}

            public function chain(SellerOrder $parcel): array
            {
                return $this->ids;
            }
        };
        $writes = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$writes): void {
            if (str_starts_with(strtolower($sql), 'insert into "parcel_provider_allocations"') && ++$writes === 2) {
                throw new \RuntimeException('Controlled provider allocation failure.');
            }
        });
        try {
            DB::transaction(function () use ($service, $order): void {
                $service->prepare(SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail());
            });
            $this->fail('A partial allocation survived caller rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled provider allocation failure.', $exception->getMessage());
        }
        $this->assertSame(0, ParcelProviderAllocation::query()->count());
        $this->assertSame(2, $writes);
    }
}

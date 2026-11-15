<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Rider\Courier;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinancialCalculationConcurrencyTest extends TestCase
{
    use UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    private function fixture(?Order $parent = null, int $subtotal = 10000, int $discount = 0): array
    {
        $parent ??= Order::factory()->create(['discount_minor' => 0]);
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create(['order_id' => $parent->id, 'subtotal_minor' => $subtotal]);
        $order->forceFill(['carrier_provider_id' => $provider->id, 'shipping_fee_minor' => 5000,
            'voucher_discount_minor' => $discount, 'shop_voucher_discount_minor' => 0,
            'platform_voucher_discount_minor' => $discount, 'platform_voucher_funding_source' => Voucher::FUNDING_MARKETPLACE,
            'shipping_discount_minor' => 0, 'seller_funded_discount_minor' => 0, 'marketplace_funded_discount_minor' => $discount])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => $subtotal + 5000 - $discount]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => $payment->amount_minor])->save();
        $cod = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => $payment->amount_minor,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'confirmed_at' => now(), 'confirmed_by_id' => $provider->id,
            'received_amount_minor' => $payment->amount_minor]);
        $cod->snapshotAllocation();
        $cod->save();

        return compact('order', 'cod', 'payment', 'provider');
    }

    private function finalize(SellerOrder $order): array
    {
        return ['action' => 'financial_finalize', 'parcel' => $order->id];
    }

    #[TestWith([500, false])]
    #[TestWith([500, true])]
    #[TestWith([1000, false])]
    public function test_duplicate_finalization_preserves_saved_rate_and_one_settlement(int $bps, bool $pendingRate): void
    {
        $f = $this->fixture();
        if ($bps === 500) {
            $f['order']->forceFill(['commission_bps' => 500, 'commission_minor' => $pendingRate ? null : 500,
                'seller_net_minor' => $pendingRate ? null : 9500, 'commission_finalized_at' => $pendingRate ? null : now()->subDay()])->save();
        }
        app(SellerSettlementService::class)->prepare($f['cod']);
        $task = $this->finalize($f['order']);
        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);
        $this->assertSame(200, $results['a']['status']);
        $this->assertSame(200, $results['b']['status']);
        $order = $f['order']->fresh();
        $this->assertSame($bps, $order->commission_bps);
        $this->assertSame($bps, $order->commission_minor);
        $this->assertSame(10000 - $bps, $order->seller_net_minor);
        $this->assertSame(1, SellerSettlement::query()->count());
        $this->assertSame($order->seller_net_minor, SellerSettlement::query()->sole()->seller_payout_minor);
        $this->assertSame($bps, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_completion_and_cod_confirmation_reconcile_in_both_lock_orders(bool $receiptFirst): void
    {
        $f = $this->fixture();
        $f['order']->forceFill(['status' => OrderStatus::Delivered])->save();
        $f['cod']->forceFill(['status' => CodRemittance::PENDING_REMITTANCE, 'received_amount_minor' => null, 'confirmed_at' => null, 'confirmed_by_id' => null])->save();
        $complete = ['action' => 'financial_complete', 'parcel' => $f['order']->id];
        $receipt = ['action' => 'cod_handover', 'provider' => $f['provider']->id, 'remittance' => $f['cod']->id,
            'data' => ['received_amount' => '150.00']];
        $results = Race::run(($receiptFirst ? $receipt : $complete) + ['hold' => 'parcel'], ($receiptFirst ? $complete : $receipt) + ['watch' => 'parcel']);
        $this->assertSame($receiptFirst ? 302 : 200, $results['a']['status']);
        $this->assertSame($receiptFirst ? 200 : 302, $results['b']['status']);
        $this->assertSame(OrderStatus::Completed, $f['order']->fresh()->status);
        $this->assertSame(1000, $f['order']->fresh()->commission_minor);
        $this->assertSame(1, SellerSettlement::query()->count());
        $settlement = SellerSettlement::query()->sole();
        $this->assertSame(9000, $settlement->seller_payout_minor);
        $this->assertNull($settlement->pending_reason);
        $this->assertSame(15000, $settlement->collected_amount_minor);
        $this->assertNull($f['cod']->fresh()->zefanya_received_at, 'Completion does not invent platform receipt.');
    }

    public function test_parallel_seller_finalizations_conserve_the_shared_checkout_discount(): void
    {
        $parent = Order::factory()->create(['discount_minor' => 300]);
        $first = $this->fixture($parent, 10000, 100);
        $second = $this->fixture($parent, 20000, 200);
        app(SellerSettlementService::class)->prepare($first['cod']);
        app(SellerSettlementService::class)->prepare($second['cod']);
        $results = Race::run($this->finalize($first['order']) + ['hold' => 'parcel'], $this->finalize($second['order']) + ['hold' => 'parcel'], bothHeld: true);
        $this->assertSame(200, $results['a']['status']);
        $this->assertSame(200, $results['b']['status']);
        $this->assertSame(2, SellerSettlement::query()->count());
        $this->assertSame(27000, (int) SellerSettlement::query()->sum('seller_payout_minor'));
        $this->assertSame(0, SellerSettlement::query()->whereNotNull('pending_reason')->count());
        $this->assertSame(2700, app(PlatformFinanceService::class)->earnings(false)['netMinor']);
        $this->assertSame(39700, (int) Payment::query()->sum('collected_amount_minor'));
    }

    public function test_three_provider_preparation_under_delivery_lock_records_each_provider_once(): void
    {
        $f = $this->fixture();
        $ids = [$f['provider']->id, ...LogisticsProvider::factory()->count(2)->create()->pluck('id')->all()];
        $hubs = collect($ids)->mapWithKeys(fn (int $id): array => [$id => LogisticsHub::query()->create([
            'logistics_provider_id' => $id, 'name' => 'Synthetic financial hub', 'hub_type' => LogisticsHub::PROVINCE,
            'province_code' => '030540000', 'province_name' => 'Pampanga', 'is_active' => true,
        ])->id]);
        foreach ([[$ids[0], $ids[1]], [$ids[1], $ids[2]]] as [$origin, $destination]) {
            // Synthetic already-received custody history; no external handoff is performed.
            DB::table('parcel_handoffs')->insert(['seller_order_id' => $f['order']->id, 'origin_provider_id' => $origin,
                'destination_provider_id' => $destination, 'sending_hub_id' => $hubs[$origin], 'receiving_hub_id' => $hubs[$destination],
                'status' => 'RECEIVED', 'direction' => 'forward', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $task = ['action' => 'financial_allocate', 'parcel' => $f['order']->id];
        $results = Race::run($task + ['hold' => 'parcel'], $task + ['watch' => 'parcel']);
        $this->assertSame(200, $results['a']['status']);
        $this->assertSame(200, $results['b']['status']);
        $this->assertSame(3, ParcelProviderAllocation::query()->count());
        $this->assertSame($ids, ParcelProviderAllocation::query()->orderBy('sequence')->pluck('logistics_provider_id')->all());
        $this->assertSame(['origin', 'intermediate', 'destination'], ParcelProviderAllocation::query()->orderBy('sequence')->pluck('role')->all());
        $this->assertSame(0, ParcelProviderAllocation::query()->whereNotNull('share_minor')->count());
        $this->assertSame(5000, $f['cod']->fresh()->keptShippingMinor() + $f['cod']->fresh()->originShareMinor());
    }

    public function test_commission_and_settlement_roll_back_together_on_mysql(): void
    {
        $f = $this->fixture();
        $settlement = app(SellerSettlementService::class)->prepare($f['cod']);
        $before = $f['order']->fresh()->getRawOriginal();
        $beforeSettlement = $settlement->fresh()->getRawOriginal();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'update `seller_settlements`')) {
                $armed = false;
                throw new \RuntimeException('Controlled MySQL commission failure.');
            }
        });
        try {
            app(CommissionFinalizationService::class)->finalize($f['order']);
            $this->fail('A settlement failure did not roll back commission.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Controlled MySQL commission failure.', $exception->getMessage());
        }
        $this->assertSame($before, $f['order']->fresh()->getRawOriginal());
        $this->assertSame($beforeSettlement, $settlement->fresh()->getRawOriginal());
        $this->assertSame(0, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    #[TestWith([1])]
    #[TestWith([5])]
    #[TestWith([10])]
    #[TestWith([9999])]
    #[TestWith([99999])]
    #[TestWith([1000000])]
    #[TestWith([100000000])]
    public function test_mysql_shipping_fallback_and_frozen_shares_reconcile_to_the_centavo(int $fee): void
    {
        $f = $this->fixture(subtotal: $fee * 2);
        $origin = LogisticsProvider::factory()->create();
        $f['order']->forceFill(['shipping_fee_minor' => $fee, 'carrier_provider_id' => $origin->id])->save();
        $f['cod']->forceFill(['kept_shipping_minor' => null, 'origin_share_minor' => null])->save();
        $read = fn () => CodRemittance::query()->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->selectRaw(CodRemittance::ORIGIN_SHARE_SQL.' as origin, '.CodRemittance::KEPT_SHIPPING_SQL.' as kept')->firstOrFail();
        $row = $read();
        $expectedOrigin = Money::percentage($fee, 40);
        $this->assertSame($expectedOrigin, (int) $row->origin);
        $this->assertSame($fee - $expectedOrigin, (int) $row->kept);
        $this->assertSame($fee, (int) $row->origin + (int) $row->kept);
        $f['cod']->unsetRelation('sellerOrder');
        $f['cod']->snapshotAllocation();
        $f['cod']->save();
        $f['order']->forceFill(['shipping_fee_minor' => $fee + 1])->save();
        $frozen = $read();
        $this->assertSame($expectedOrigin, (int) $frozen->origin);
        $this->assertSame($fee - $expectedOrigin, (int) $frozen->kept);
        $this->assertSame($fee, (int) $frozen->origin + (int) $frozen->kept);
    }
}

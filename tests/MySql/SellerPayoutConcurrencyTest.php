<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SellerPayoutConcurrencyTest extends TestCase
{
    private static bool $migrated = false;

    public function createApplication()
    {
        return Preflight::application();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$migrated) {
            Preflight::prepareSchema();
            self::$migrated = true;
        }
        Preflight::clean();
    }

    private function fixture(): array
    {
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'admin']);
        $parent = Order::factory()->create(['discount_minor' => 5000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 69900,
        ]);
        $order->forceFill(['shipping_fee_minor' => 6500, 'voucher_discount_minor' => 5000,
            'voucher_funding_source' => Voucher::FUNDING_SELLER])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 71400]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 71400])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $order->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 71400,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 71400, 'zefanya_received_at' => now(),
            'confirmed_at' => now(), 'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        $settlement = app(SellerSettlementService::class)->prepare($remittance);
        app(CommissionFinalizationService::class)->finalize($order);
        $this->assertSame(61655, app(SellerPayoutRequestService::class)->availableBalance($seller));

        return [$seller, $admin, $settlement->fresh(), [
            'order' => $order->fresh()->getRawOriginal(), 'payment' => $payment->fresh()->getRawOriginal(),
            'remittance' => $remittance->fresh()->getRawOriginal(),
        ]];
    }

    private function payout(Seller $seller, string $key): array
    {
        return ['action' => 'payout_request', 'seller' => $seller->id, 'amount' => '100.00', 'key' => $key];
    }

    private function settlement(SellerSettlement $settlement, Admin $admin, string $reference = 'MYSQL-SETTLEMENT-A'): array
    {
        return ['action' => 'settlement', 'settlement' => $settlement->id, 'admin' => $admin->id,
            'amount_minor' => 61655, 'reference' => $reference];
    }

    private function assertCleanOverlap(array $results, SellerSettlement $settlement, array $snapshots): void
    {
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
            $this->assertArrayNotHasKey('exception', $result);
        }
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
        $this->assertSame($snapshots['order'], $settlement->sellerOrder->fresh()->getRawOriginal());
        $this->assertSame($snapshots['payment'], $settlement->payment->fresh()->getRawOriginal());
        $this->assertSame($snapshots['remittance'], $settlement->codRemittance->fresh()->getRawOriginal());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_duplicate_payout_requests_reserve_once_with_the_same_or_different_submission_keys(bool $sameKey): void
    {
        [$seller, , $settlement, $snapshots] = $this->fixture();
        $key = (string) Str::uuid();
        $results = Race::run(
            $this->payout($seller, $key) + ['hold' => 'seller'],
            $this->payout($seller, $sameKey ? $key : (string) Str::uuid()) + ['watch' => 'seller'],
        );

        $this->assertSame([201, $sameKey ? 200 : 422], array_column($results, 'status'));
        if ($sameKey) {
            $this->assertSame($results['a']['payout_id'], $results['b']['payout_id']);
        } else {
            $this->assertSame(['amount'], $results['b']['validation_fields']);
        }
        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(10000, SellerPayoutRequest::query()->sole()->amount_minor);
        $this->assertSame(10000, (int) SellerPayoutAllocation::query()->sum('amount_minor'));
        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_payout_reservation_and_admin_settlement_cannot_spend_the_same_earnings(bool $reservationFirst): void
    {
        [$seller, $admin, $settlement, $snapshots] = $this->fixture();
        $reservation = $this->payout($seller, (string) Str::uuid());
        $payment = $this->settlement($settlement, $admin);
        $results = Race::run(
            ($reservationFirst ? $reservation : $payment) + ['hold' => 'seller'],
            ($reservationFirst ? $payment : $reservation) + ['watch' => 'seller'],
        );

        $this->assertSame([$reservationFirst ? 201 : 200, 422], array_column($results, 'status'));
        $this->assertSame([$reservationFirst ? 'settlement' : 'amount'], $results['b']['validation_fields']);
        $this->assertSame($reservationFirst ? SellerSettlement::PENDING_PAYOUT : SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame($reservationFirst ? null : 61655, $settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_payout_requests', $reservationFirst ? 1 : 0);
        $this->assertDatabaseCount('seller_payout_allocations', $reservationFirst ? 1 : 0);
        $this->assertSame($reservationFirst ? 51655 : 0, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }

    public function test_two_admins_cannot_settle_the_same_earnings_twice(): void
    {
        [, $admin, $settlement, $snapshots] = $this->fixture();
        $otherAdmin = Admin::factory()->create(['role' => 'admin']);
        $results = Race::run(
            $this->settlement($settlement, $admin) + ['hold' => 'seller'],
            $this->settlement($settlement, $otherAdmin, 'MYSQL-SETTLEMENT-B') + ['watch' => 'seller'],
        );

        $this->assertSame([200, 422], array_column($results, 'status'));
        $this->assertSame(['settlement'], $results['b']['validation_fields']);
        $this->assertDatabaseCount('seller_settlements', 1);
        $this->assertSame(61655, $settlement->fresh()->paid_amount_minor);
        $this->assertSame('MYSQL-SETTLEMENT-A', $settlement->fresh()->payout_reference);
        $this->assertSame($admin->id, $settlement->fresh()->paid_by_admin_id);
        $this->assertDatabaseCount('seller_payout_requests', 0);
        $this->assertCleanOverlap($results, $settlement, $snapshots);
    }
}

<?php

namespace Tests\Feature\Logistics;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Rider\Courier;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\LogisticsFinanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Logistics\ProviderAllocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiProviderAllocationSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $providers;

    private array $hubs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->providers = LogisticsProvider::factory()->count(3)->create(['status' => 'approved', 'email_verified_at' => now()])->all();
        $this->hubs = array_map(fn ($provider) => LogisticsHub::query()->create(['logistics_provider_id' => $provider->id, 'name' => 'Synthetic province hub', 'hub_type' => LogisticsHub::PROVINCE, 'region_code' => '030000000', 'region_name' => 'Region III', 'province_code' => '030540000', 'province_name' => 'Pampanga', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100', 'is_active' => true, 'approval_status' => LogisticsHub::APPROVAL_APPROVED]), $this->providers);
    }

    private function parcel(int $count = 3): CodRemittance
    {
        $shipment = SellerOrder::factory()->create(['shipping_fee_minor' => 5001]);
        $shipment->forceFill(['carrier_provider_id' => $this->providers[0]->id])->save();
        for ($i = 0; $i < $count - 1; $i++) {
            $this->handoff($shipment, $i, $i + 1);
        }
        $remittance = new CodRemittance;
        $remittance->forceFill(['seller_order_id' => $shipment->id,
            'payment_id' => Payment::factory()->create(['seller_order_id' => $shipment->id])->id,
            'courier_id' => Courier::factory()->create(['logistics_provider_id' => $this->providers[$count - 1]->id])->id,
            'logistics_provider_id' => $this->providers[$count - 1]->id, 'amount_minor' => 15001,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS]);
        $remittance->snapshotAllocation();
        $remittance->save();

        return $remittance;
    }

    private function handoff(SellerOrder $shipment, int $from, int $to, string $status = 'RECEIVED', string $direction = 'forward'): void
    {
        DB::table('parcel_handoffs')->insert(['seller_order_id' => $shipment->id,
            'origin_provider_id' => $this->providers[$from]->id, 'destination_provider_id' => $this->providers[$to]->id,
            'sending_hub_id' => $this->hubs[$from]->id, 'receiving_hub_id' => $this->hubs[$to]->id,
            'status' => $status, 'direction' => $direction, 'received_at' => $status === 'RECEIVED' ? now() : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertBlocked(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsupported allocation was finalized.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_one_and_two_providers_keep_integer_rounding_and_ignore_extra_hub_handoffs(): void
    {
        $one = $this->parcel(1);
        $two = $this->parcel(2);
        $this->assertSame(0, $one->originShareMinor());
        $this->assertSame(5001, $one->keptShippingMinor());
        $this->assertSame(2000, $two->originShareMinor());
        $this->assertSame(3001, $two->keptShippingMinor());
        $this->handoff($two->sellerOrder, 1, 0);
        $this->handoff($two->sellerOrder, 0, 1);
        $this->handoff($two->sellerOrder, 1, 2, 'REQUESTED');
        $this->assertFalse($two->allocationRequiresApproval());
        $this->assertSame(2, count(app(ProviderAllocationService::class)->chain($two->sellerOrder)));
        $this->assertSame(5001, $two->originShareMinor() + $two->keptShippingMinor());
    }

    public function test_three_providers_are_amountless_idempotent_and_excluded_from_ready_payables(): void
    {
        $parcel = $this->parcel();
        $shipment = $parcel->sellerOrder;
        $before = $shipment->getAttributes();
        $allocations = app(ProviderAllocationService::class);
        $this->assertSame(3, $allocations->prepare($shipment));
        $this->assertSame(0, $allocations->prepare($shipment));
        $this->assertSame([null, null, null], ParcelProviderAllocation::orderBy('sequence')->pluck('share_minor')->all());
        $this->assertNull($parcel->kept_shipping_minor);
        $this->assertNull($parcel->origin_share_minor);
        $this->assertNull($parcel->dueToZefanyaMinor());
        $service = app(LogisticsRemittanceService::class);
        $this->assertCount(0, $service->readyParcels($this->providers[2]));
        $this->assertCount(1, $service->allocationHolds());
        $this->assertBlocked(fn () => $service->submit($this->providers[2], [$parcel->id], 12000, 'TEST-THREE'));
        $this->assertSame(0, LogisticsRemittance::count());
        $this->assertSame($before, $shipment->fresh()->getAttributes());
        $this->assertSame(0, app(LogisticsFinanceService::class)->statement($this->providers[2])['grossMinor']);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))->assertOk()->assertSee('Shipping allocation requires approval');
        $this->get(route('admin.dashboard'))->assertOk()->assertViewHas('settlements', fn ($rows) => $rows->contains(fn ($row) => ($row['allocationHeldMinor'] ?? 0) === 15001 && $row['owedMinor'] === 0));
        $this->actingAs($this->providers[2], 'logistics')->get(route('logistics.earnings.index'))->assertOk()->assertSee('Allocation pending');
    }

    public function test_existing_two_provider_snapshots_become_held_without_rewriting_after_third_provider_receipt(): void
    {
        $parcel = $this->parcel(2);
        $snapshot = $parcel->only(['origin_share_minor', 'kept_shipping_minor']);
        $this->handoff($parcel->sellerOrder, 1, 2);
        $this->assertTrue($parcel->allocationRequiresApproval());
        $this->assertNull($parcel->keptShippingMinor());
        $this->assertSame($snapshot, $parcel->fresh()->only(['origin_share_minor', 'kept_shipping_minor']));
        $sql = CodRemittance::query()->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->where('cod_remittances.id', $parcel->id)->selectRaw(CodRemittance::KEPT_SHIPPING_SQL.' as kept')->first();
        $this->assertNull($sql->kept);
    }

    public function test_submitted_batches_and_policy_notes_cannot_bypass_the_guard(): void
    {
        $parcel = $this->parcel();
        $batch = new LogisticsRemittance;
        $batch->forceFill(['logistics_provider_id' => $this->providers[2]->id, 'parcel_count' => 1,
            'cod_total_minor' => 15001, 'shipping_total_minor' => 3001, 'amount_minor' => 12000,
            'reference' => 'BEFORE-GUARD', 'status' => LogisticsRemittance::SUBMITTED])->save();
        $parcel->forceFill(['logistics_remittance_id' => $batch->id])->save();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        app(ProviderAllocationService::class)->approvePolicy($admin, 'A policy note is not a validated allocation algorithm.');
        $this->assertBlocked(fn () => app(LogisticsRemittanceService::class)->confirm($batch, $admin, 12000));
        $this->assertSame(LogisticsRemittance::SUBMITTED, $batch->fresh()->status);
        $this->assertNull($parcel->fresh()->zefanya_received_at);
        $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))->assertOk()->assertSee('Allocation on hold');
    }

    public function test_finalized_snapshots_are_unchanged_but_no_new_unsupported_shipping_payout_can_be_recorded(): void
    {
        $parcel = $this->parcel();
        $parcel->forceFill(['origin_share_minor' => 2000, 'kept_shipping_minor' => 3001, 'zefanya_received_at' => now(),
            'reconciliation' => LogisticsRemittanceService::RECONCILE_ALLOCATE, 'destination_share_minor' => 3001])->save();
        $before = $parcel->fresh()->getAttributes();
        $this->assertSame(2000, $parcel->originShareMinor());
        $this->assertSame(3001, $parcel->keptShippingMinor());
        $service = app(LogisticsRemittanceService::class);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->assertBlocked(fn () => $service->payOriginShare($admin, $this->providers[0], $parcel->id, 2000, 'BAD-ORIGIN', now()->toDateString()));
        $this->assertBlocked(fn () => $service->payDestinationShare($admin, $parcel, 'BAD-DEST', now()->toDateString()));
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $this->assertCount(0, $service->destinationOwed());
    }

    public function test_supported_parcels_can_still_be_remitted_while_another_route_is_held(): void
    {
        $held = $this->parcel();
        $supported = $this->parcel(1);
        $service = app(LogisticsRemittanceService::class);
        $batch = $service->submit($this->providers[0], [$supported->id], 10000, 'SUPPORTED-ONLY');
        $this->assertSame(10000, $batch->amount_minor);
        $this->assertNull($held->fresh()->logistics_remittance_id);
        $this->assertBlocked(fn () => $service->submit($this->providers[0], [$supported->id], 10000, 'SUPPORTED-ONLY'));
        $this->assertSame(1, LogisticsRemittance::count());
    }

    public function test_unknown_historical_multi_provider_shares_are_not_reconstructed_as_forty_sixty(): void
    {
        $parcel = $this->parcel();
        $parcel->forceFill(['zefanya_received_at' => now()])->save();
        $before = $parcel->fresh()->getAttributes();
        $this->assertNull($parcel->originShareMinor());
        $this->assertNull($parcel->keptShippingMinor());
        $this->assertNull($parcel->dueToZefanyaMinor());
        $sql = CodRemittance::query()->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->where('cod_remittances.id', $parcel->id)->selectRaw(CodRemittance::KEPT_SHIPPING_SQL.' as kept')->first();
        $this->assertNull($sql->kept);
        $this->assertSame($before, $parcel->fresh()->getAttributes());
        $this->assertSame(15001, app(LogisticsRemittanceService::class)->totals($this->providers[2])[LogisticsRemittanceService::STAGE_SETTLED]['codMinor']);
    }

    public function test_three_provider_hold_does_not_block_physical_rider_cash_receipt(): void
    {
        $parcel = $this->parcel();
        $parcel->forceFill(['status' => CodRemittance::PENDING_REMITTANCE])->save();
        $parcel->payment->forceFill(['method' => PaymentMethod::Cod, 'status' => PaymentStatus::Paid,
            'amount_minor' => 15001, 'collected_amount_minor' => 15001, 'collected_at' => now()])->save();
        $this->assertTrue(app(CodRemittanceService::class)->confirmReceipt($parcel, $this->providers[2], 15001));
        $parcel->refresh();
        $this->assertSame(15001, $parcel->received_amount_minor);
        $this->assertSame(CodRemittance::REMITTED_TO_LOGISTICS, $parcel->status);
        $this->assertNull($parcel->kept_shipping_minor);
        $this->assertNull($parcel->origin_share_minor);
        $this->assertTrue($parcel->allocationPending());
    }
}

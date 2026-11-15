<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\DeliveryProof;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Rider\RiderEarning;
use App\Models\Shared\Payment;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\PickupPolicy;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class FinancialLedgerIntegrityConcurrencyTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs, UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
    }

    public function test_concurrent_real_deliveries_record_one_collection_obligation_proof_and_rider_earning(): void
    {
        $network = $this->operationalHandoffProvider('Audit16', '043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $seller = $this->sellerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $buyer = $this->buyerIn('043400000', 'Laguna', '043405000', 'Calamba', '0434050001', 'Bucal');
        $parcel = $this->handoffParcel($seller, $buyer, $network['provider'], 'AUDIT16-COD-RACE', OrderStatus::OutForDelivery);
        $rider = Rider::factory()->create(['logistics_provider_id' => $network['provider']->id,
            'station_hub_id' => $network['station']->id, 'status' => 'approved', 'email_verified_at' => now(), 'is_online' => true]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $network['provider']->id,
            'rider_id' => $rider->id, 'area_id' => $network['area']->id]);
        $parcel->forceFill(['delivery_courier_id' => $courier->id, 'destination_area_id' => $network['area']->id,
            'custody_provider_id' => $network['provider']->id, 'custody_hub_id' => $network['station']->id,
            'carrier_provider_id' => $network['provider']->id, 'subtotal_minor' => 10000, 'shipping_fee_minor' => 5000])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 15000]);
        $task = ['action' => 'rider_cod_delivery', 'parcel' => $parcel->id, 'rider' => $rider->id,
            'amount' => 15000];
        // Hold after the payment update, inside the actual POD/COD transaction.
        // The other delivery waits at the parcel lock; no direct payment-writer shortcut.
        $results = Race::run($task + ['hold' => 'cod_collection_write'], $task + ['watch' => 'parcel']);
        $this->assertTrue($results['a']['success']);
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame(15000, $payment->fresh()->collected_amount_minor);
        $this->assertSame($courier->id, $payment->fresh()->collected_by_id);
        $this->assertSame(1, CodRemittance::query()->count());
        $this->assertSame(15000, (int) CodRemittance::query()->sole()->amount_minor);
        $this->assertSame(1, DeliveryProof::query()->count());
        $this->assertSame(1, RiderEarning::query()->where('job', RiderEarning::DELIVERY)->count());
        $this->assertSame((new PickupPolicy)->rates()['delivery_minor'], (int) RiderEarning::query()->sum('amount_minor'));
        $this->assertSame(1, StatusHistory::query()->where('to_status', OrderStatus::Delivered->value)->count());
        $this->assertNull($parcel->fresh()->commission_finalized_at);
        $this->assertNotNull(Race::$evidence[array_key_last(Race::$evidence)]['observed_overlap']);
    }
}

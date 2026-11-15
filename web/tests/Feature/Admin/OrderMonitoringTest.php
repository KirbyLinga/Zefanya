<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryProof;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Admin\OrderMonitoringService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class OrderMonitoringTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): Admin
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_only_admin_and_super_admin_can_monitor(): void
    {
        $shipment = SellerOrder::factory()->create();
        foreach (['/admin/orders', '/admin/orders/'.$shipment->id] as $url) {
            $this->get($url)->assertRedirect();
        }
        foreach ([['buyer', Buyer::factory()->create()], ['seller', Seller::factory()->create()], ['logistics', LogisticsProvider::factory()->create()], ['rider', Rider::factory()->create()]] as [$guard, $user]) {
            $this->actingAs($user, $guard)->get('/admin/orders')->assertRedirect();
            $this->get('/admin/orders/'.$shipment->id)->assertRedirect();
            auth($guard)->logout();
        }
        foreach (['support', 'moderator'] as $role) {
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->get('/admin/orders')->assertForbidden();
            $this->get('/admin/orders/'.$shipment->id)->assertForbidden();
        }
        foreach (['admin', 'super_admin'] as $role) {
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->get('/admin/orders')->assertOk();
            $this->get('/admin/orders/'.$shipment->id)->assertOk();
        }
    }

    public function test_list_is_paginated_and_parent_order_search_includes_every_seller_shipment(): void
    {
        $this->admin();
        $order = Order::factory()->create(['reference' => 'MONITOR-MULTI-SELLER']);
        $seller = Seller::factory()->create(['business_name' => 'Monitor shop']);
        SellerOrder::factory()->count(27)->create(['order_id' => $order->id]);
        $response = $this->get('/admin/orders?q=MONITOR-MULTI-SELLER')->assertOk();
        $this->assertSame(27, $response->viewData('shipments')->total());
        $this->assertCount(25, $response->viewData('shipments'));
        $response->assertSee('aria-label="Previous page"', false)->assertSee('aria-label="Next page"', false);
        $page = $this->get('/admin/orders?q=MONITOR-MULTI-SELLER&page=2')->assertOk();
        $this->assertCount(2, $page->viewData('shipments'));
        $this->assertSame(27, $this->get('/admin/orders?q='.$order->id)->viewData('shipments')->total());
    }

    public function test_searches_buyer_seller_tracking_and_shipment_id(): void
    {
        $this->admin();
        $buyer = Buyer::factory()->create(['first_name' => 'UniqueBuyer']);
        $shipment = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id])->id,
            'seller_id' => Seller::factory()->create(['business_name' => 'UniqueSeller'])->id,
            'tracking_number' => 'UNIQUE-TRACKING',
        ]);
        SellerOrder::factory()->create();
        foreach (['UniqueBuyer', 'UniqueSeller', 'UNIQUE-TRACKING', (string) $shipment->id] as $term) {
            $rows = $this->get('/admin/orders?q='.$term)->assertOk()->viewData('shipments');
            $this->assertContains($shipment->id, $rows->pluck('id')->all());
        }
    }

    public function test_status_provider_date_and_existing_attention_filters_compose(): void
    {
        $this->admin();
        $provider = LogisticsProvider::factory()->create();
        $shipment = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create();
        $shipment->forceFill(['carrier_provider_id' => $provider->id, 'pickup_missed_windows' => 2, 'created_at' => '2026-11-10 12:00:00'])->save();
        SellerOrder::factory()->create();
        $url = '/admin/orders?status=READY_FOR_PICKUP&provider='.$provider->id.'&from=2026-11-10&to=2026-11-10&attention=unassigned';
        $rows = $this->get($url)->assertOk()->viewData('shipments');
        $this->assertSame([$shipment->id], $rows->pluck('id')->all());
        $this->assertSame(1, $this->get('/admin/orders?attention=missed_pickup')->viewData('shipments')->total());
        $this->assertSame(0, $this->get('/admin/orders?from=2026-11-11')->viewData('shipments')->where('id', $shipment->id)->count());
        $this->get('/admin/orders?to=2026-11-10')->assertOk();
        $this->get('/admin/orders?status=bogus')->assertSessionHasErrors('status');
        $this->get('/admin/orders?from=2026-11-10&to=2026-11-09')->assertSessionHasErrors('to');
    }

    public function test_open_incident_and_outstanding_transfer_indicators_do_not_duplicate_shipments(): void
    {
        $this->admin();
        [$shipment, $manifest] = $this->manifestFixture();
        foreach ([1, 2] as $number) {
            Incidents::query()->create(['incident_seller_order_id' => $shipment->id, 'type' => 'damaged', 'description' => 'Issue '.$number, 'reporter_type' => 'logistics', 'status' => 'open']);
        }
        $manifest->forceFill(['status' => 'DISPATCHED', 'dispatched_at' => now()])->save();
        $this->assertSame(1, $this->get('/admin/orders?attention=incident')->assertOk()->viewData('shipments')->total());
        $this->assertSame(1, $this->get('/admin/orders?attention=transfer')->assertOk()->viewData('shipments')->total());
        $this->get('/admin/orders')->assertSee('Open incident')->assertSee('Outstanding transfer');
    }

    public function test_status_and_assignment_activity_is_chronological_and_records_actors(): void
    {
        $admin = $this->admin();
        $shipment = SellerOrder::factory()->create();
        $first = Courier::factory()->create(['name' => 'Original pickup rider']);
        $second = Courier::factory()->create(['name' => 'Replacement pickup rider']);
        $this->freezeTime();
        StatusHistory::record($shipment, OrderStatus::Placed, OrderStatus::Confirmed, 'Seller operator', 'Seller accepted', OrderActor::Seller, $shipment->seller_id, 'web');
        $this->travel(1)->minutes();
        DeliveryAssignment::query()->create(['seller_order_id' => $shipment->id, 'courier_id' => $first->id, 'role' => 'pickup', 'assigned_by_type' => 'logistics', 'assigned_by_id' => $first->logistics_provider_id, 'notes' => 'Initial assignment']);
        $this->travel(1)->minutes();
        DeliveryAssignment::query()->create(['seller_order_id' => $shipment->id, 'courier_id' => $second->id, 'role' => 'pickup', 'assigned_by_type' => 'logistics', 'assigned_by_id' => $second->logistics_provider_id, 'notes' => 'Replaced due to availability']);
        OperationsAuditLog::record($admin, 'rider_reassigned', $shipment, 'Shipment', ['courier_id' => $first->id], ['courier_id' => $second->id], 'Manual reassignment reason');
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $response->assertSeeInOrder(['Seller accepted', 'Original pickup rider', 'Replacement pickup rider'])
            ->assertSee('Seller #'.$shipment->seller_id)->assertSee('Source: web')->assertSee('Manual reassignment reason');
        $this->assertSame(4, $response->viewData('activity')->total());
    }

    public function test_assignment_custody_fields_and_status_alone_do_not_prove_physical_custody(): void
    {
        $this->admin();
        $courier = Courier::factory()->create();
        $shipment = SellerOrder::factory()->status(OrderStatus::OutForDelivery)->create(['courier_id' => $courier->id, 'delivery_courier_id' => $courier->id]);
        $shipment->forceFill(['custody_provider_id' => $courier->logistics_provider_id, 'carrier_provider_id' => $courier->logistics_provider_id])->save();
        StatusHistory::record($shipment, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, 'Rider', actor: OrderActor::Rider, actorId: $courier->id);
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk()->assertSee('Not yet verified');
        $this->assertNull($response->viewData('custodian'));
    }

    private function manifestFixture(): array
    {
        $provider = LogisticsProvider::factory()->create();
        $origin = LogisticsHub::query()->create(['logistics_provider_id' => $provider->id, 'name' => 'Origin Station', 'hub_type' => LogisticsHub::PROVINCE, 'province_name' => 'Laguna', 'municipality' => 'Calamba']);
        $destination = LogisticsHub::query()->create(['logistics_provider_id' => $provider->id, 'name' => 'Verified Destination', 'hub_type' => LogisticsHub::STATION, 'parent_hub_id' => $origin->id, 'province_name' => 'Laguna', 'municipality' => 'Los Banos']);
        $shipment = SellerOrder::factory()->create();
        $manifest = Manifest::query()->create(['logistics_provider_id' => $provider->id, 'origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id]);
        $item = ManifestItem::query()->create(['manifest_id' => $manifest->id, 'seller_order_id' => $shipment->id]);

        return [$shipment, $manifest, $item];
    }

    public function test_manifest_level_receipt_does_not_verify_an_unscanned_parcel(): void
    {
        $this->admin();
        [$shipment, $manifest] = $this->manifestFixture();
        $manifest->forceFill(['status' => 'RECEIVED', 'received_at' => now()])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertNull($response->viewData('custodian'));
    }

    public function test_receiving_scan_is_used_even_with_newer_unrelated_status_history(): void
    {
        $this->admin();
        [$shipment, $manifest, $item] = $this->manifestFixture();
        $item->forceFill(['received_at' => now()->subHour(), 'received_by_type' => 'logistics', 'received_by_id' => $manifest->logistics_provider_id])->save();
        StatusHistory::record($shipment, OrderStatus::Sorted, OrderStatus::AssignedToRider, actor: OrderActor::Logistics, actorId: $manifest->logistics_provider_id);
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame('Verified Destination', $response->viewData('custodian')['holder']);
        $this->get('/admin/orders')->assertOk()->assertSee('Verified Destination');
    }

    public function test_verified_pickup_and_later_delivery_proof_are_distinct_from_assignments(): void
    {
        $this->admin();
        $this->freezeTime();
        $shipment = SellerOrder::factory()->create();
        $courier = Courier::factory()->create();
        $shipment->forceFill(['handover_at' => now()])->save();
        StatusHistory::record($shipment, OrderStatus::ReadyForPickup, OrderStatus::PickedUp, 'Pickup operator', actor: OrderActor::Rider, actorId: $courier->id, source: 'web');
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame('Pickup courier #'.$courier->id, $response->viewData('custodian')['holder']);
        DeliveryProof::query()->create(['seller_order_id' => $shipment->id, 'courier_id' => $courier->id, 'received_by' => 'Actual recipient', 'submitted_at' => now()->addHour(), 'photo_path' => 'private.jpg', 'photo_mime' => 'image/jpeg', 'photo_size' => 1]);
        $this->assertSame('Delivery recipient: Actual recipient', $this->get('/admin/orders/'.$shipment->id)->assertOk()->viewData('custodian')['holder']);
    }

    private function financialFixture(): array
    {
        $shipment = SellerOrder::factory()->create();
        $courier = Courier::factory()->create();
        $shipment->forceFill(['shipping_fee_minor' => 1000, 'carrier_provider_id' => $courier->logistics_provider_id])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $shipment->id, 'amount_minor' => 11000, 'method' => 'cod', 'status' => 'paid', 'collected_amount_minor' => 11000, 'collected_at' => now()]);
        $cod = new CodRemittance;
        $cod->forceFill(['seller_order_id' => $shipment->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id, 'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 11000, 'received_amount_minor' => 8000, 'shortage_minor' => 4000, 'shortage_recovered_minor' => 1000, 'status' => CodRemittance::PARTIALLY_REMITTED])->save();
        $settlement = new SellerSettlement;
        $settlement->forceFill(['seller_order_id' => $shipment->id, 'seller_id' => $shipment->seller_id, 'payment_id' => $payment->id, 'cod_remittance_id' => $cod->id, 'collected_amount_minor' => 11000, 'shipping_allocation_minor' => 1000, 'commission_minor' => 500, 'seller_payout_minor' => 9500, 'paid_amount_minor' => 3000])->save();

        return [$shipment, $cod, $settlement];
    }

    public function test_financials_use_parcel_amounts_and_authoritative_shortage_and_payout_balances(): void
    {
        $this->admin();
        [$shipment, $cod, $settlement] = $this->financialFixture();
        $payout = new SellerPayoutRequest;
        $payout->forceFill(['seller_id' => $shipment->seller_id, 'amount_minor' => 999900, 'idempotency_key' => Str::uuid(), 'status' => 'PAID'])->save();
        $allocation = new SellerPayoutAllocation;
        $allocation->forceFill(['seller_payout_request_id' => $payout->id, 'seller_settlement_id' => $settlement->id, 'amount_minor' => 3000])->save();
        $batch = new LogisticsRemittance;
        $batch->forceFill(['logistics_provider_id' => $cod->logistics_provider_id, 'parcel_count' => 4, 'cod_total_minor' => 88000, 'shipping_total_minor' => 4000, 'amount_minor' => 84000, 'reference' => 'SHARED-BATCH', 'status' => 'CONFIRMED', 'received_amount_minor' => 84000])->save();
        $cod->forceFill(['logistics_remittance_id' => $batch->id])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $response->assertSee('₱110.00')->assertSee('₱30.00')->assertSee('₱65.00')->assertDontSee('₱9,999.00');
        $this->assertSame(3000, $response->viewData('financials')['cod']->outstandingShortageMinor());
        $this->assertNull($response->viewData('financials')['cod']->zefanya_received_at, 'A confirmed batch alone must not prove receipt of this parcel.');
        $this->assertSame(6500, $response->viewData('shipment')->settlement->remainingPayoutMinor());
    }

    public function test_sibling_shipments_are_linked_and_missing_money_is_not_reported_paid(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->create();
        $sibling = SellerOrder::factory()->create(['order_id' => $shipment->order_id]);
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $response->assertSee(route('admin.orders.show', $sibling))->assertSee('No COD remittance recorded')->assertSee('No Seller settlement recorded');
        $this->assertSame(2, $response->viewData('siblings')->total());
    }

    public function test_monitoring_gets_make_no_database_writes_and_have_no_mutation_routes(): void
    {
        $this->admin();
        [$shipment] = $this->financialFixture();
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame([], $writes);
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->$method('/admin/orders/'.$shipment->id)->assertStatus(405);
        }
        $this->get('/admin/orders/99999999')->assertNotFound();
    }

    public function test_list_query_count_does_not_grow_per_shipment(): void
    {
        $this->admin();
        [$shipment, $manifest, $item] = $this->manifestFixture();
        $item->forceFill(['received_at' => now()])->save();
        $this->get('/admin/orders')->assertOk(); // Warm runtime schema caches.
        DB::enableQueryLog();
        $this->get('/admin/orders')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();
        for ($i = 0; $i < 8; $i++) {
            $parcel = SellerOrder::factory()->create(['order_id' => $shipment->order_id]);
            ManifestItem::query()->create(['manifest_id' => $manifest->id, 'seller_order_id' => $parcel->id, 'received_at' => now()]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/orders')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 2, $many);
    }

    public function test_activity_and_sibling_history_are_independently_paginated(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->create();
        for ($i = 0; $i < 35; $i++) {
            StatusHistory::record($shipment, OrderStatus::Placed, OrderStatus::Confirmed, note: 'Event '.$i, actor: OrderActor::Seller, actorId: $shipment->seller_id);
        }
        $response = $this->get('/admin/orders/'.$shipment->id.'?activity_page=2')->assertOk();
        $this->assertSame(35, $response->viewData('activity')->total());
        $this->assertCount(5, $response->viewData('activity'));
        $this->assertSame(1, $response->viewData('siblings')->currentPage());
    }

    public function test_recorded_failed_delivery_reassignment_and_handoff_exceptions_can_be_filtered(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create();
        $shipment->forceFill(['needs_reassignment' => true])->save();
        ParcelHandoffException::query()->create([
            'seller_order_id' => $shipment->id,
            'origin_provider_id' => LogisticsProvider::factory()->create()->id,
            'status' => 'OPEN', 'reason' => 'No available partner',
        ]);
        foreach (['failed_delivery', 'reassignment', 'handoff_exception'] as $indicator) {
            $response = $this->get('/admin/orders?attention='.$indicator)->assertOk();
            $this->assertSame([$shipment->id], $response->viewData('shipments')->pluck('id')->all());
        }
    }

    public function test_return_collection_and_seller_receipt_supersede_outbound_delivery_evidence(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->create();
        $courier = Courier::factory()->create(['name' => 'Return collector']);
        DeliveryProof::query()->create(['seller_order_id' => $shipment->id, 'courier_id' => $courier->id, 'received_by' => 'Buyer recipient', 'submitted_at' => now()->subDays(2), 'photo_path' => 'private.jpg', 'photo_mime' => 'image/jpeg', 'photo_size' => 1]);
        $return = ParcelReturn::query()->create(['seller_order_id' => $shipment->id, 'reason' => 'Return requested', 'requested_at' => now()->subDay()]);
        $return->forceFill(['collected_at' => now()->subHour(), 'collection_courier_id' => $courier->id])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame('Return collector', $response->viewData('custodian')['holder']);
        $return->forceFill(['seller_received_at' => now(), 'received_by_seller_id' => $shipment->seller_id])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame('Seller: '.$shipment->seller->business_name, $response->viewData('custodian')['holder']);
    }

    public function test_refunds_debts_and_unpaid_rider_earnings_remain_separate_obligations(): void
    {
        $this->admin();
        [$shipment, $cod, $settlement] = $this->financialFixture();
        $return = ParcelReturn::query()->create(['seller_order_id' => $shipment->id, 'reason' => 'Refund due', 'requested_at' => now()]);
        $return->forceFill(['refund_amount_minor' => 4500, 'refunded_at' => now(), 'refund_reference' => 'REFUND-REFERENCE'])->save();
        $debt = new SellerDebt;
        $debt->forceFill(['seller_order_id' => $shipment->id, 'seller_id' => $shipment->seller_id, 'seller_settlement_id' => $settlement->id, 'reason' => 'return_after_payout', 'amount_minor' => 2000, 'settled_minor' => 500])->save();
        $earning = new RiderEarning;
        $earning->forceFill(['seller_order_id' => $shipment->id, 'courier_id' => $cod->courier_id, 'logistics_provider_id' => $cod->logistics_provider_id, 'job' => 'delivery', 'amount_minor' => 5000, 'earned_at' => now()])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $response->assertSee('REFUND-REFERENCE')->assertSee('₱45.00')->assertSee('₱15.00')->assertSee('₱50.00')->assertSee('Unpaid');
        $this->assertSame(5000, $response->viewData('financials')['unpaidRider']);
    }

    public function test_missing_settlement_financials_are_unknown_and_snapshot_shipping_allocation_is_preserved(): void
    {
        $this->admin();
        [$shipment, $cod, $settlement] = $this->financialFixture();
        $settlement->forceFill(['seller_payout_minor' => null, 'paid_amount_minor' => null, 'pending_reason' => 'commission_finalization_missing'])->save();
        $cod->forceFill(['origin_share_minor' => 123, 'kept_shipping_minor' => 877])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $response->assertSee('Not finalized')->assertSee('commission_finalization_missing')->assertSee('₱1.23')->assertSee('₱8.77');
        $this->assertSame(123, $response->viewData('financials')['cod']->originShareMinor());
    }

    public function test_accepted_handoff_is_only_a_plan_and_verified_receipt_identifies_the_destination(): void
    {
        $admin = $this->admin();
        $origin = $this->operationalHandoffProvider('Commercial carrier', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $destination = $this->operationalHandoffProvider('Receiving carrier', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'National Capital Region');
        $link = $this->activeHandoffLink($origin['provider'], $destination['provider'], $admin);
        $shipment = SellerOrder::factory()->create();
        $shipment->forceFill(['carrier_provider_id' => $origin['provider']->id])->save();
        $handoff = new ParcelHandoff;
        $handoff->forceFill([
            'seller_order_id' => $shipment->id, 'logistics_provider_link_id' => $link->id,
            'origin_provider_id' => $origin['provider']->id, 'destination_provider_id' => $destination['provider']->id,
            'sending_hub_id' => $origin['province']->id, 'receiving_hub_id' => $destination['province']->id,
            'status' => 'ACCEPTED', 'requested_at' => now()->subHour(), 'requested_by_type' => 'logistics', 'requested_by_id' => $origin['provider']->id,
            'accepted_at' => now(), 'accepted_by_type' => 'logistics', 'accepted_by_id' => $destination['provider']->id,
        ])->save();
        $this->assertNull($this->get('/admin/orders/'.$shipment->id)->assertOk()->viewData('custodian'));
        $handoff->forceFill([
            'status' => 'RECEIVED', 'dispatched_at' => now(), 'dispatched_by_type' => 'logistics', 'dispatched_by_id' => $origin['provider']->id,
            'received_at' => now()->addHour(), 'received_by_type' => 'logistics', 'received_by_id' => $destination['provider']->id,
        ])->save();
        $response = $this->get('/admin/orders/'.$shipment->id)->assertOk();
        $this->assertSame($destination['province']->name, $response->viewData('custodian')['holder']);
        $this->assertSame($origin['provider']->id, $response->viewData('shipment')->carrier_provider_id);
    }

    public function test_status_age_requires_evidence_and_same_status_movements_do_not_reset_it(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->status(OrderStatus::Confirmed)->create();
        $monitor = app(OrderMonitoringService::class);
        $this->assertNull($monitor->statusSince($monitor->query()->findOrFail($shipment->id)));
        $first = StatusHistory::record($shipment, OrderStatus::Placed, OrderStatus::Confirmed, actor: OrderActor::Seller, actorId: $shipment->seller_id);
        $first->forceFill(['created_at' => now()->subDay()])->save();
        StatusHistory::record($shipment, OrderStatus::Confirmed, OrderStatus::Confirmed, note: 'Additional movement record');
        $this->assertTrue($monitor->statusSince($monitor->query()->findOrFail($shipment->id))->equalTo($first->fresh()->created_at));
    }

    public function test_summary_counts_are_platform_wide_and_card_filters_match_with_overlapping_attention(): void
    {
        $this->admin();
        $pickup = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create();
        $pickup->forceFill(['pickup_missed_windows' => 2, 'needs_reassignment' => true])->save();
        $transit = SellerOrder::factory()->status(OrderStatus::InTransit)->create();
        SellerOrder::factory()->status(OrderStatus::Delivered)->create();
        SellerOrder::factory()->status(OrderStatus::Completed)->create();
        SellerOrder::factory()->status(OrderStatus::Returned)->create();
        SellerOrder::factory()->status(OrderStatus::Cancelled)->create();
        SellerOrder::factory()->status(OrderStatus::Lost)->create();
        SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create();
        SellerOrder::factory()->count(25)->create();

        $response = $this->get('/admin/orders?q=does-not-match')->assertOk();
        $this->assertSame(0, $response->viewData('shipments')->total());
        $this->assertSame(['active' => 28, 'pickup' => 1, 'transit' => 1, 'attention' => 2], $response->viewData('summary'));
        foreach (['active' => 28, 'pickup' => 1, 'transit' => 1, 'attention' => 2] as $filter => $count) {
            $response->assertSee(route('admin.orders.index', ['summary' => $filter]));
            $page = $this->get('/admin/orders?summary='.$filter)->assertOk();
            $this->assertSame($count, $page->viewData('shipments')->total());
            $this->assertSame($count, $page->viewData('summary')[$filter]);
        }
        $this->assertSame([$pickup->id], $this->get('/admin/orders?summary=pickup')->viewData('shipments')->pluck('id')->all());
        $this->assertSame([$transit->id], $this->get('/admin/orders?summary=transit')->viewData('shipments')->pluck('id')->all());
        $this->get('/admin/orders?summary=unexpected')->assertSessionHasErrors('summary');
    }

    public function test_summary_attention_count_matches_existing_incident_and_outstanding_transfer_indicators(): void
    {
        $this->admin();
        [$shipment, $manifest] = $this->manifestFixture();
        Incidents::query()->create(['incident_seller_order_id' => $shipment->id, 'type' => 'damaged', 'description' => 'Damage', 'reporter_type' => 'logistics', 'status' => 'open']);
        $manifest->forceFill(['status' => 'DISPATCHED', 'dispatched_at' => now()])->save();
        $response = $this->get('/admin/orders?summary=attention')->assertOk();
        $this->assertSame(1, $response->viewData('summary')['attention']);
        $this->assertSame([$shipment->id], $response->viewData('shipments')->pluck('id')->all());
    }

    public function test_list_hides_long_identifiers_and_shows_prioritized_attention_with_accessible_detail_links(): void
    {
        $this->admin();
        $order = Order::factory()->create(['reference' => 'ORD-LONG-IDENTIFIER-12345678901234567890']);
        $shipment = SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create(['order_id' => $order->id, 'tracking_number' => 'TRK-LONG-IDENTIFIER-12345678901234567890']);
        $shipment->forceFill(['pickup_missed_windows' => 2, 'needs_reassignment' => true])->save();
        Incidents::query()->create(['incident_seller_order_id' => $shipment->id, 'type' => 'damaged', 'description' => 'Damage', 'reporter_type' => 'logistics', 'status' => 'open']);
        $response = $this->get('/admin/orders?q=TRK-LONG-IDENTIFIER-12345678901234567890')->assertOk();
        $response->assertDontSee('ORD-LONG-IDENTIFIER-12345678901234567890')
            ->assertDontSee('None recorded')->assertSee('+2 more')
            ->assertSee('View details for shipment #'.$shipment->id);
        $this->get('/admin/orders/'.$shipment->id)->assertOk()
            ->assertSee('ORD-LONG-IDENTIFIER-12345678901234567890')
            ->assertSee('TRK-LONG-IDENTIFIER-12345678901234567890');
    }

    public function test_list_link_and_detail_back_link_preserve_search_filters_and_page(): void
    {
        $this->admin();
        $shipment = SellerOrder::factory()->create();
        $listQuery = http_build_query(['q' => 'test search', 'status' => 'PLACED', 'page' => 2]);
        $detail = $this->get('/admin/orders/'.$shipment->id.'?'.http_build_query(['list' => $listQuery]))->assertOk();
        $detail->assertSee(route('admin.orders.index').'?'.$listQuery);
        $this->get('/admin/orders?summary=pickup')->assertOk()->assertSee('Awaiting Pickup');
    }
}

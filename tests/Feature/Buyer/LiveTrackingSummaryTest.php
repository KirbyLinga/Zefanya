<?php

namespace Tests\Feature\Buyer;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\ParcelRoute;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class LiveTrackingSummaryTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    public function test_order_without_a_saved_route_shows_an_honest_scan_placeholder_and_next_step(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create(['order_id' => $order->id]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.show', $order))->assertOk()
            ->assertSeeText('No verified scan recorded yet')->assertSeeText('Awaiting pickup from the seller.')
            ->assertSeeText('Physical hub not yet verified')->assertSee('aria-label="Delivery milestones"', false);
    }

    public function test_planned_destination_is_not_current_custody_and_only_real_scan_timestamps_are_used(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $hubs = LogisticsHub::query()->where('logistics_provider_id', $this->checkoutCarrier->id)->get();
        $origin = $hubs->firstWhere('hub_type', LogisticsHub::STATION);
        $destination = $hubs->firstWhere('hub_type', LogisticsHub::PROVINCE);
        $parcel = SellerOrder::factory()->status(OrderStatus::AtSortingCenter)->create(['seller_id' => $seller->id]);
        ParcelRoute::query()->create(['seller_order_id' => $parcel->id, 'origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id,
            'legs' => [['hub_id' => $destination->id, 'transport_mode' => 'ROAD']], 'current_leg_index' => 0, 'route_planned_at' => now()]);
        $manifest = Manifest::query()->create(['logistics_provider_id' => $this->checkoutCarrier->id,
            'origin_hub_id' => $origin->id, 'destination_hub_id' => $destination->id]);
        $item = ManifestItem::query()->create(['manifest_id' => $manifest->id, 'seller_order_id' => $parcel->id]);
        $service = app(RouteExecutionService::class);

        $summary = $service->trackingSummary($parcel->fresh());
        $this->assertNull($summary['last_scan']);
        $this->assertNull($summary['last_verified_hub']);
        $this->assertSame('Awaiting dispatch to '.$destination->name.'.', $summary['next_step']);

        $scanAt = now()->subMinutes(2)->startOfSecond();
        $item->forceFill(['verified_for_dispatch_at' => $scanAt])->save();
        $manifest->forceFill(['status' => ManifestStatus::Dispatched, 'dispatched_at' => now()->subMinute()])->save();
        $summary = $service->trackingSummary($parcel->fresh());
        $this->assertSame($scanAt->toDateTimeString(), $summary['last_scan']['at']->toDateTimeString());
        $this->assertSame('Scanned at '.$origin->name, $summary['last_scan']['label']);
        $this->assertSame('In transit between hubs', $summary['location']);
        $this->assertSame('Awaiting a verified receiving scan at '.$destination->name.'.', $summary['next_step']);

        $receivedAt = now()->startOfSecond();
        $item->markReceived('logistics', $this->checkoutCarrier->id);
        $parcel->forceFill(['custody_hub_id' => $destination->id, 'custody_provider_id' => $this->checkoutCarrier->id])->save();
        $summary = $service->trackingSummary($parcel->fresh());
        $this->assertSame('Received at '.$destination->name, $summary['last_scan']['label']);
        $this->assertSame($receivedAt->toDateTimeString(), $summary['last_scan']['at']->toDateTimeString());
        $this->assertSame($destination->name, $summary['last_verified_hub']);
    }

    public function test_return_next_step_does_not_tell_the_buyer_to_confirm_a_delivery(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create();
        ParcelReturn::query()->create(['seller_order_id' => $parcel->id, 'status' => ParcelReturnStatus::AwaitingSeller,
            'reason' => 'Damaged item', 'requested_at' => now()]);

        $summary = app(RouteExecutionService::class)->trackingSummary($parcel->fresh());

        $this->assertSame('Awaiting the seller to confirm physical receipt.', $summary['next_step']);
        $this->assertSame('At origin hub awaiting seller receipt', $summary['location']);
    }

    public function test_tracking_stays_scoped_to_the_order_owner(): void
    {
        $order = Order::factory()->create();

        $this->actingAs(Buyer::factory()->create(), 'buyer')->get(route('buyer.orders.show', $order))->assertNotFound();
    }

    public static function finalDeliverySteps(): array
    {
        return [
            'unverified attempt' => [OrderStatus::DeliveryFailed, 0, 'Logistics is reviewing the delivery attempt before deciding the next step.'],
            'first failure' => [OrderStatus::DeliveryFailed, 1, 'Awaiting Logistics to arrange the final delivery attempt or a return.'],
            'attempts exhausted' => [OrderStatus::DeliveryFailed, 2, 'Logistics will arrange return to the seller.'],
            'delivered' => [OrderStatus::Delivered, 0, 'Check your parcel, then confirm receipt.'],
            'completed' => [OrderStatus::Completed, 0, 'No further delivery steps. This order is complete.'],
            'cancelled' => [OrderStatus::Cancelled, 0, 'No further delivery steps. This shipment was cancelled.'],
        ];
    }

    #[DataProvider('finalDeliverySteps')]
    public function test_terminal_and_failed_delivery_next_steps_respect_the_existing_lifecycle(OrderStatus $status, int $attempts, string $next): void
    {
        $parcel = SellerOrder::factory()->status($status)->create(['delivery_attempts' => $attempts]);

        $this->assertSame($next, app(RouteExecutionService::class)->trackingSummary($parcel)['next_step']);
    }

    public function test_manual_status_override_does_not_become_a_scan_and_private_notes_stay_private(): void
    {
        $this->freezeTime();
        $parcel = SellerOrder::factory()->status(OrderStatus::AtSortingCenter)->create(['handover_at' => now()]);
        StatusHistory::record($parcel, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            note: 'Manual Logistics status override. Reason: Private investigation details.', actor: OrderActor::Logistics, actorId: 1);
        $service = app(RouteExecutionService::class);
        $this->assertNull($service->trackingSummary($parcel->fresh())['last_scan']);

        $this->travel(1)->minute();
        StatusHistory::record($parcel, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            note: 'Parcel handed to sorting center.'.ParcelHandoffVerification::noteSuffix(ParcelHandoffVerification::METHOD_CAMERA),
            actor: OrderActor::Logistics, actorId: 1);
        $summary = $service->trackingSummary($parcel->fresh());
        $this->assertSame('Parcel received at a hub', $summary['last_scan']['label']);
        $this->assertSame(now()->toDateTimeString(), $summary['last_scan']['at']->toDateTimeString());
        $this->assertStringNotContainsString('Private investigation', json_encode($summary));
    }

    public function test_held_shipment_next_step_never_promises_departure(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::AtSortingCenter)->create();
        Incidents::query()->create(['incident_seller_order_id' => $parcel->id, 'reporter_type' => 'logistics', 'reporter_id' => 1,
            'type' => 'missing', 'description' => 'Private warehouse investigation', 'happened_at' => now()]);

        $summary = app(RouteExecutionService::class)->trackingSummary($parcel->fresh());

        $this->assertTrue($summary['held']);
        $this->assertSame('Logistics is resolving a delivery exception before the parcel can move.', $summary['next_step']);
        $this->assertStringNotContainsString('Private warehouse', json_encode($summary));
    }
}

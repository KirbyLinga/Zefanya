<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryMonitoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function courier(?LogisticsProvider $provider = null): Courier
    {
        return Courier::factory()->create([
            'logistics_provider_id' => ($provider ?? $this->provider)->id,
            'is_available' => false,
        ]);
    }

    private function order(OrderStatus $status, ?Courier $courier = null, ?Courier $deliveryCourier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'status' => $status->value,
            'courier_id' => $courier?->id,
            'delivery_courier_id' => $deliveryCourier?->id,
        ])->fresh();
    }

    public function test_index_lists_claimed_parcels_with_status_and_riders(): void
    {
        $pickup = $this->courier();
        $delivery = $this->courier();

        $assigned = $this->order(OrderStatus::AssignedToRider, $pickup, $delivery);
        $out = $this->order(OrderStatus::OutForDelivery, $pickup, $delivery);
        $delivered = $this->order(OrderStatus::Delivered, $pickup, $delivery);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk()
            ->assertSee('#'.$assigned->id)
            ->assertSee('#'.$out->id)
            ->assertSee('#'.$delivered->id)
            ->assertSee('Out for delivery')
            ->assertSee($delivery->name);
    }

    public function test_failed_parcel_shows_reason_label_and_notes(): void
    {
        $pickup = $this->courier();
        $delivery = $this->courier();

        $failed = $this->order(OrderStatus::DeliveryFailed, $pickup, $delivery);
        $failed->forceFill([
            'delivery_failure_reason' => 'recipient_unavailable',
            'delivery_failure_notes' => 'Customer not home at 14:30.',
        ])->save();

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk()
            ->assertSee('Recipient unavailable')
            ->assertSee('Customer not home at 14:30.');
    }

    public function test_legacy_failed_row_without_reason_renders_fallback(): void
    {
        // Pre-reason-column row: status is DELIVERY_FAILED but both columns are null.
        $failed = $this->order(OrderStatus::DeliveryFailed, $this->courier(), $this->courier());
        $this->assertNull($failed->delivery_failure_reason);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk()
            ->assertSee('No reason recorded');
    }

    public function test_page_is_read_only_with_no_transition_forms(): void
    {
        $pickup = $this->courier();
        $delivery = $this->courier();
        $so = $this->order(OrderStatus::OutForDelivery, $pickup, $delivery);

        $response = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk();

        $html = $response->getContent();

        // No form may target any status-changing endpoint (the sidebar's
        // logout form is the only <form> allowed on the page).
        $transitionRoutes = [
            route('logistics.parcel-sorting.sort', $so),
            route('logistics.parcel-sorting.assignDelivery', $so),
            route('logistics.incoming-parcels.toSortingCenter', $so),
        ];

        foreach ($transitionRoutes as $url) {
            $this->assertStringNotContainsString($url, $html, "monitoring must not link to {$url}");
        }
    }

    public function test_excludes_unclaimed_and_other_providers_parcels(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $otherCourier = $this->courier($otherProvider);

        // Other provider's claimed parcel â€” must not leak.
        $theirs = $this->order(OrderStatus::OutForDelivery, $otherCourier, $otherCourier);

        // Pre-pickup statuses are not part of monitoring.
        $awaiting = $this->order(OrderStatus::ReadyForPickup);

        // Anything claimed by this provider's fleet is in scope: mine sits at
        // AtSortingCenter here only to assert it is excluded from the narrowed
        // monitoring set while remaining visible on the sorting screen.
        $mine = $this->order(OrderStatus::AtSortingCenter, $this->courier());
        $mineDispatch = $this->order(OrderStatus::OutForDelivery, $this->courier(), $this->courier());

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk()
            ->assertDontSee('#'.$mine->id)
            ->assertSee('#'.$mineDispatch->id)
            ->assertDontSee('#'.$theirs->id)
            ->assertDontSee('#'.$awaiting->id);
    }

    public function test_guest_is_redirected_to_home_login_modal(): void
    {
        $this->get(route('logistics.delivery-monitoring.index'))
            ->assertRedirect(route('home', ['login' => 1]));
    }

    public function test_returned_parcels_are_not_monitored(): void
    {
        $so = $this->order(OrderStatus::Returned, $this->courier(), $this->courier());

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertOk()
            ->assertDontSee('#'.$so->id);
    }

    public function test_non_logistics_guard_is_denied(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($buyer, 'buyer')
            ->get(route('logistics.delivery-monitoring.index'))
            ->assertRedirect(route('home', ['login' => 1]));
    }
}

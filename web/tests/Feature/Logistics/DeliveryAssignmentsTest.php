<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeliveryAssignmentsTest extends TestCase
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

    private function order(OrderStatus $status, ?Courier $deliveryCourier = null): SellerOrder
    {
        return SellerOrder::factory()->create([
            'status' => $status->value,
            'delivery_courier_id' => $deliveryCourier?->id,
        ])->fresh();
    }

    public function test_index_lists_assigned_to_rider_and_out_for_delivery_shipments(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $assigned = $this->order(OrderStatus::AssignedToRider, $courier);
        $outForDelivery = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-assignments.index'))
            ->assertOk()
            ->assertSee('#'.$assigned->id)
            ->assertSee('#'.$outForDelivery->id);
    }

    public function test_start_delivery_advances_to_out_for_delivery(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::AssignedToRider, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.start', $so))
            ->assertRedirect()
            ->assertSessionHas('success', 'Delivery started.');

        $so->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $so->status);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $so->id)->count());
    }

    public function test_start_delivery_rejected_if_already_started(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.start', $so))
            ->assertRedirect()
            ->assertSessionHas('error');

        $so->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $so->status);
    }

    public function test_mark_delivered_advances_and_releases_delivery_courier(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.delivered', $so))
            ->assertRedirect()
            ->assertSessionHas('success');

        $so->refresh();
        $this->assertSame(OrderStatus::Delivered, $so->status);
        $this->assertTrue($courier->fresh()->is_available, 'delivery courier released');
        $this->assertSame(1, StatusHistory::where('seller_order_id', $so->id)->count());
    }

    public function test_mark_failed_advances_to_delivery_failed(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        // First, GET the form
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-assignments.failed.form', $so))
            ->assertOk()
            ->assertSee('Mark Delivery Failed');

        // Then POST with a valid reason
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'recipient_unavailable',
                'failure_notes' => 'Customer not home',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $so->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $so->status);
        $this->assertSame('recipient_unavailable', $so->delivery_failure_reason);
        $this->assertSame('Customer not home', $so->delivery_failure_notes);
    }

    public function test_mark_failed_requires_reason(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [])
            ->assertRedirect()
            ->assertSessionHasErrors('failure_reason');

        $so->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $so->status);
    }

    public function test_mark_failed_other_requires_notes(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'other',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('failure_notes');

        $so->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $so->status);
    }

    public function test_mark_failed_other_with_notes_works(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'other',
                'failure_notes' => 'Custom reason details',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $so->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $so->status);
        $this->assertSame('other', $so->delivery_failure_reason);
        $this->assertSame('Custom reason details', $so->delivery_failure_notes);
    }

    public function test_mark_delivered_rejected_if_not_out_for_delivery(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::AssignedToRider, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.delivered', $so))
            ->assertRedirect()
            ->assertSessionHas('error');

        $so->refresh();
        $this->assertSame(OrderStatus::AssignedToRider, $so->status);
    }

    /**
     * Provider B may not touch Provider A's parcel: sort, assign-delivery,
     * dispatch, delivered, failed POST and the failed GET form must all 403
     * via SellerOrderFleetPolicy, with no state change and no history rows.
     */
    public function test_other_provider_is_forbidden_on_sort_assign_start_delivered_and_failed(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $aCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => false,
        ]);

        $actAsB = fn () => $this->actingAs($this->provider, 'logistics');

        // Slices owned by Provider A, one per required status.
        $atSorting = SellerOrder::factory()->create([
            'status' => OrderStatus::AtSortingCenter->value,
            'courier_id' => $aCourier->id,
        ])->fresh();
        $sorted = SellerOrder::factory()->create([
            'status' => OrderStatus::Sorted->value,
            'courier_id' => $aCourier->id,
        ])->fresh();
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => false,
        ]);
        $assigned = SellerOrder::factory()->create([
            'status' => OrderStatus::AssignedToRider->value,
            'courier_id' => $aCourier->id,
            'delivery_courier_id' => $deliveryCourier->id,
        ])->fresh();
        $outForDelivery = SellerOrder::factory()->create([
            'status' => OrderStatus::OutForDelivery->value,
            'courier_id' => $aCourier->id,
            'delivery_courier_id' => $deliveryCourier->id,
        ])->fresh();

        $attempts = [
            'sort' => fn () => $actAsB()->post(route('logistics.parcel-sorting.sort', $atSorting)),
            'assign' => fn () => $actAsB()->post(route('logistics.parcel-sorting.assignDelivery', $sorted)),
            'start' => fn () => $actAsB()->post(route('logistics.delivery-assignments.start', $assigned)),
            'delivered' => fn () => $actAsB()->post(route('logistics.delivery-assignments.delivered', $outForDelivery)),
            'failed_post' => fn () => $actAsB()->post(route('logistics.delivery-assignments.failed', $outForDelivery), ['failure_reason' => 'recipient_unavailable']),
            'failed_get' => fn () => $actAsB()->get(route('logistics.delivery-assignments.failed.form', $outForDelivery)),
        ];

        $expectations = [
            [$atSorting, OrderStatus::AtSortingCenter],
            [$sorted, OrderStatus::Sorted],
            [$assigned, OrderStatus::AssignedToRider],
            [$outForDelivery, OrderStatus::OutForDelivery],
            [$outForDelivery, OrderStatus::OutForDelivery],
            [$outForDelivery, OrderStatus::OutForDelivery],
        ];

        foreach (array_keys($attempts) as $i => $key) {
            [$slice, $status] = $expectations[$i];

            $beforeFailures = [
                $slice->delivery_failure_reason,
                $slice->delivery_courier_id,
            ];

            $attempts[$key]()->assertForbidden();

            $fresh = $slice->fresh();
            $this->assertSame($status, $fresh->status, "{$key}: status unchanged");
            $this->assertSame(
                $beforeFailures,
                [$fresh->delivery_failure_reason, $fresh->delivery_courier_id],
                "{$key}: courier/reason columns unchanged"
            );
            $this->assertSame(0, StatusHistory::where('seller_order_id', $slice->id)->count(), "{$key}: no history written");
        }
    }

    public function test_mark_failed_releases_the_delivery_courier_atomically(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'recipient_unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $so->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $so->status);
        $this->assertTrue(
            $courier->fresh()->is_available,
            'the failed leg frees the delivery rider for the next assignment'
        );
        $this->assertSame(1, StatusHistory::where('seller_order_id', $so->id)->count());
    }

    public function test_mark_failed_post_rejected_if_not_out_for_delivery(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::AssignedToRider, $courier);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'recipient_unavailable',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel is not out for delivery.');

        $so->refresh();
        $this->assertSame(OrderStatus::AssignedToRider, $so->status);
        $this->assertNull($so->delivery_failure_reason, 'no reason may be stored on a rejected attempt');
    }

    public function test_failed_form_rejected_if_not_out_for_delivery(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::AssignedToRider, $courier);

        // Regression: this used to TypeError (View-only return type).
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.delivery-assignments.failed.form', $so))
            ->assertRedirect()
            ->assertSessionHas('error', 'This parcel is not out for delivery.');
    }

    /**
     * The failure confirmation uses the `warning` channel (a failure is not a
     * "success") — prove it still RENDERS on the page after the redirect.
     */
    public function test_failed_warning_flash_renders_on_the_page(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $so = $this->order(OrderStatus::OutForDelivery, $courier);

        $this->from(route('logistics.delivery-assignments.index'));

        $response = $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.failed', $so), [
                'failure_reason' => 'wrong_address',
                'failure_notes' => 'House number missing.',
            ])
            ->assertRedirect(route('logistics.delivery-assignments.index'))
            ->assertSessionHas('warning', 'Delivery marked as failed.');

        $this->actingAs($this->provider, 'logistics')
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Delivery marked as failed.', false);
    }
}

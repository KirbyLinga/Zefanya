<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Exhaustive proof of the status graph in SellerOrderStatusService::ALLOWED.
 *
 * The expected map below is DELIBERATELY duplicated (not read from the
 * service): if someone widens ALLOWED, this test fails until the change is
 * mirrored here on purpose — an illegal skip can never slip through silently.
 *
 * actor is omitted on every call (null skips the ROLE gate) so these cases
 * assert the GRAPH alone: role ownership is OrderActorMapTest's job.
 */
class OrderStatusGraphTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LEGAL = [
        'PLACED' => ['CONFIRMED', 'READY_FOR_PICKUP'],
        'CONFIRMED' => ['PREPARING'],
        'PREPARING' => ['READY_FOR_PICKUP'],
        'READY_FOR_PICKUP' => ['PICKED_UP'],
        'PICKED_UP' => ['AT_SORTING_CENTER'],
        'AT_SORTING_CENTER' => ['SORTED'],
        'SORTED' => ['ASSIGNED_TO_RIDER'],
        'ASSIGNED_TO_RIDER' => ['OUT_FOR_DELIVERY'],
        'OUT_FOR_DELIVERY' => ['DELIVERED', 'DELIVERY_FAILED'],
        'DELIVERED' => ['COMPLETED'],
        'DELIVERY_FAILED' => ['RETURNED'],
        'RETURNED' => ['COMPLETED'],
        'COMPLETED' => [],
    ];

    private SellerOrderStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SellerOrderStatusService;
    }

    private function orderAt(OrderStatus $status): SellerOrder
    {
        $so = SellerOrder::factory()->create();
        $so->forceFill(['status' => $status->value])->save();

        return $so->fresh();
    }

    public function test_every_legal_edge_succeeds_and_writes_history(): void
    {
        $checked = 0;

        foreach (self::LEGAL as $from => $targets) {
            foreach ($targets as $to) {
                $so = $this->orderAt(OrderStatus::from($from));
                $before = StatusHistory::count();

                $result = $this->service->transition($so, OrderStatus::from($to));

                $this->assertSame($to, $result->status->value, "legal hop {$from} → {$to}");
                $this->assertSame($before + 1, StatusHistory::count(), "history row for {$from} → {$to}");
                $checked++;
            }
        }

        // 14 legal edges in the canonical pipeline (incl. failed/return).
        $this->assertSame(14, $checked);
    }

    public function test_every_illegal_pair_is_rejected_with_no_writes(): void
    {
        $rejected = 0;

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                // Self-transition: idempotent no-op (no write) — tested separately.
                if ($from === $to) {
                    continue;
                }

                $isLegal = in_array($to->value, self::LEGAL[$from->value] ?? [], true);
                if ($isLegal) {
                    continue;
                }

                $so = $this->orderAt($from);
                $before = StatusHistory::count();

                try {
                    $this->service->transition($so, $to);
                    $this->fail("Illegal hop {$from->value} → {$to->value} was accepted.");
                } catch (\InvalidArgumentException) {
                    // Expected: graph gate rejected the pair.
                }

                $this->assertSame(
                    $from,
                    $so->refresh()->status,
                    "status must not change for illegal {$from->value} → {$to->value}"
                );
                $this->assertSame(
                    $before,
                    StatusHistory::count(),
                    "illegal attempts never write history ({$from->value} → {$to->value})"
                );
                $rejected++;
            }
        }

        // 13×13 = 169 pairs − 13 self-transitions − 14 legal edges = 142.
        $this->assertSame(142, $rejected);
    }

    /** The specific skips called out in review must all be rejected. */
    public function test_named_illegal_skips_are_rejected(): void
    {
        $skips = [
            [OrderStatus::AtSortingCenter, OrderStatus::OutForDelivery],
            [OrderStatus::Sorted, OrderStatus::OutForDelivery],
            [OrderStatus::ReadyForPickup, OrderStatus::AtSortingCenter],
            [OrderStatus::AtSortingCenter, OrderStatus::AssignedToRider],
            [OrderStatus::PickedUp, OrderStatus::AssignedToRider],
            [OrderStatus::ReadyForPickup, OrderStatus::AssignedToRider],
            [OrderStatus::OutForDelivery, OrderStatus::Completed],
            [OrderStatus::Delivered, OrderStatus::OutForDelivery], // reverse
            [OrderStatus::Completed, OrderStatus::Delivered],      // terminal
        ];

        foreach ($skips as [$from, $to]) {
            $so = $this->orderAt($from);

            try {
                $this->service->transition($so, $to);
                $this->fail("Skip {$from->value} → {$to->value} was accepted.");
            } catch (\InvalidArgumentException) {
            }

            $this->assertSame($from, $so->refresh()->status, "{$from->value} → {$to->value}");
            $this->assertSame(0, StatusHistory::count());
        }
    }

    /** Self-transition stays an idempotent no-op: no write, no history row. */
    public function test_self_transition_is_a_silent_no_op(): void
    {
        $so = $this->orderAt(OrderStatus::Sorted);

        $result = $this->service->transition($so, OrderStatus::Sorted);

        $this->assertSame(OrderStatus::Sorted, $result->status);
        $this->assertSame(0, StatusHistory::count());
    }

    /**
     * HTTP-level proof: no logistics route can shove a SORTED parcel straight
     * to OUT_FOR_DELIVERY — the dispatch action refuses anything that is not
     * ASSIGNED_TO_RIDER before the state machine is even consulted.
     */
    public function test_dispatch_route_rejects_a_sorted_parcel(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $provider->id,
            'is_available' => false,
        ]);
        $so = SellerOrder::factory()->create([
            'status' => OrderStatus::Sorted->value,
            'courier_id' => $courier->id,
        ])->fresh();

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.delivery-assignments.start', $so))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::Sorted, $so->refresh()->status);
        $this->assertSame(0, StatusHistory::count());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ProviderHandoffSchemaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 5.1 — cross-provider link and handoff schema/model foundations.
 *
 * Schema lifecycle only: these tests never exercise checkout, parcel status
 * transitions, or manifest workflows.
 */
class ProviderHandoffDatabaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private LogisticsHub $sendingHub;

    private LogisticsHub $receivingHub;

    private SellerOrder $parcel;

    private ProviderHandoffSchemaService $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->origin = $this->provider();
        $this->destination = $this->provider();
        $this->sendingHub = $this->provinceHub($this->origin, 'Origin Province Hub', 'San Fernando');
        $this->receivingHub = $this->provinceHub($this->destination, 'Destination Province Hub', 'Angeles');
        $receivingStation = $this->stationHub($this->destination, $this->receivingHub, 'Destination Receiving Station');
        $this->coverage($this->destination, $receivingStation);
        $this->parcel = $this->parcel('TRK-PHASE51-0001');
        $this->links = app(ProviderHandoffSchemaService::class);
    }

    // ── Schema ───────────────────────────────────────────────────────────────

    public function test_link_and_handoff_tables_expose_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('logistics_provider_links'));
        $this->assertTrue(Schema::hasTable('parcel_handoffs'));

        foreach ([
            'origin_provider_id',
            'destination_provider_id',
            'priority',
            'status',
            'origin_consented_by',
            'origin_consented_at',
            'destination_consented_by',
            'destination_consented_at',
            'approved_by',
            'approved_at',
            'active_slot',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('logistics_provider_links', $column), "Missing logistics_provider_links.{$column}");
        }

        $handoffColumns = [
            'seller_order_id',
            'logistics_provider_link_id',
            'origin_provider_id',
            'destination_provider_id',
            'sending_hub_id',
            'receiving_hub_id',
            'status',
            'rejection_reason',
            'active_seller_order_id',
        ];

        foreach (['requested', 'accepted', 'rejected', 'dispatched', 'received'] as $event) {
            $handoffColumns[] = $event.'_at';
            $handoffColumns[] = $event.'_by_type';
            $handoffColumns[] = $event.'_by_id';
        }

        foreach ($handoffColumns as $column) {
            $this->assertTrue(Schema::hasColumn('parcel_handoffs', $column), "Missing parcel_handoffs.{$column}");
        }
    }

    public function test_handoff_status_enum_covers_the_required_lifecycle(): void
    {
        $this->assertSame(
            ['REQUESTED', 'ACCEPTED', 'REJECTED', 'IN_TRANSIT', 'RECEIVED'],
            array_map(fn (ParcelHandoffStatus $status): string => $status->value, ParcelHandoffStatus::cases()),
        );
    }

    // ── Provider links ───────────────────────────────────────────────────────

    public function test_link_request_starts_pending_with_origin_consent_only(): void
    {
        $link = $this->links->requestLink($this->origin, $this->destination, priority: 4);

        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertSame(4, $link->priority);
        $this->assertSame($this->origin->id, (int) $link->origin_consented_by);
        $this->assertNotNull($link->origin_consented_at);
        $this->assertNull($link->destination_consented_by);
        $this->assertNull($link->destination_consented_at);
        $this->assertNull($link->approved_by);
        $this->assertNull($link->approved_at);
        $this->assertNull(DB::table('logistics_provider_links')->where('id', $link->id)->value('active_slot'));
    }

    public function test_link_activates_only_after_both_consents_and_admin_approval(): void
    {
        $link = $this->links->requestLink($this->origin, $this->destination);

        // One consent + Admin approval is still not enough.
        $this->links->approve($link, $this->admin);
        $this->assertSame(LogisticsProviderLink::PENDING, $link->fresh()->status);

        $this->links->consent($link, $this->destination);
        $link->refresh();

        $this->assertSame(LogisticsProviderLink::ACTIVE, $link->status);
        $this->assertSame($this->origin->id, (int) $link->origin_consented_by);
        $this->assertSame($this->destination->id, (int) $link->destination_consented_by);
        $this->assertSame($this->admin->id, (int) $link->approved_by);
        $this->assertNotNull($link->approved_at);
        $this->assertSame(1, (int) DB::table('logistics_provider_links')->where('id', $link->id)->value('active_slot'));

        // Destination consent first also waits for Admin approval.
        $link->forceFill(['status' => LogisticsProviderLink::INACTIVE])->save();
        $second = $this->links->requestLink($this->origin, $this->destination);
        $this->links->consent($second, $this->destination);
        $this->assertSame(LogisticsProviderLink::PENDING, $second->fresh()->status);
        $this->links->approve($second, $this->admin);
        $this->assertSame(LogisticsProviderLink::ACTIVE, $second->fresh()->status);
    }

    public function test_only_participating_providers_may_consent(): void
    {
        $link = $this->links->requestLink($this->origin, $this->destination);
        $outsider = $this->provider();

        try {
            $this->links->consent($link, $outsider);
            $this->fail('An unrelated provider was allowed to consent.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($link->fresh()->destination_consented_at);
    }

    public function test_self_links_are_rejected(): void
    {
        $this->assertValidationFailure(
            fn () => $this->links->requestLink($this->origin, $this->origin),
            'destination_provider_id',
        );
    }

    public function test_both_providers_must_be_approved(): void
    {
        $pending = LogisticsProvider::factory()->pending()->create();

        $this->assertValidationFailure(
            fn () => $this->links->requestLink($pending, $this->destination),
            'provider',
        );

        $this->assertValidationFailure(
            fn () => $this->links->requestLink($this->origin, $pending),
            'provider',
        );
    }

    public function test_both_providers_must_be_province_verified(): void
    {
        $unverified = LogisticsProvider::factory()->create([
            'province_verified_at' => null,
            'province_verified_by' => null,
        ]);

        $this->assertValidationFailure(
            fn () => $this->links->requestLink($this->origin, $unverified),
            'provider',
        );

        $this->assertValidationFailure(
            fn () => $this->links->requestLink($unverified, $this->destination),
            'provider',
        );
    }

    public function test_archived_providers_cannot_form_links(): void
    {
        $archived = LogisticsProvider::factory()->create(['archived_at' => now()]);

        $this->assertValidationFailure(
            fn () => $this->links->requestLink($this->origin, $archived),
            'provider',
        );
    }

    public function test_link_direction_is_immutable(): void
    {
        $link = $this->links->requestLink($this->origin, $this->destination);
        $third = $this->provider();

        $this->assertValidationFailure(function () use ($link, $third): void {
            $link->forceFill(['origin_provider_id' => $third->id])->save();
        }, 'link');
    }

    public function test_duplicate_active_links_are_rejected_by_the_database(): void
    {
        $this->activeLink();

        $duplicate = $this->links->requestLink($this->origin, $this->destination);
        $this->links->consent($duplicate, $this->destination);

        try {
            $this->links->approve($duplicate, $this->admin);
            $this->fail('A second active link for the same directed pair was allowed.');
        } catch (QueryException) {
            // Expected: the generated active_slot unique key rejects it.
        }

        $this->assertSame(1, LogisticsProviderLink::query()->where('status', LogisticsProviderLink::ACTIVE)->count());
        $this->assertSame(LogisticsProviderLink::PENDING, $duplicate->fresh()->status);
    }

    public function test_inactive_link_history_allows_a_new_active_link(): void
    {
        $first = $this->activeLink();
        $first->forceFill(['status' => LogisticsProviderLink::INACTIVE])->save();

        $this->assertSame(LogisticsProviderLink::INACTIVE, $first->fresh()->status);
        $this->assertNull(DB::table('logistics_provider_links')->where('id', $first->id)->value('active_slot'));

        $second = $this->activeLink();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, LogisticsProviderLink::query()->count());
        $this->assertSame(1, LogisticsProviderLink::query()->where('status', LogisticsProviderLink::ACTIVE)->count());
    }

    public function test_active_status_requires_consent_and_approval_metadata(): void
    {
        $link = $this->links->requestLink($this->origin, $this->destination);

        $this->assertValidationFailure(function () use ($link): void {
            $link->forceFill([
                'status' => LogisticsProviderLink::ACTIVE,
                'destination_consented_by' => $this->destination->id,
                'destination_consented_at' => now(),
            ])->save();
        }, 'status');
    }

    public function test_models_expose_link_and_provider_relationships(): void
    {
        $link = $this->activeLink();

        $this->assertSame($this->origin->id, $link->originProvider->id);
        $this->assertSame($this->destination->id, $link->destinationProvider->id);
        $this->assertSame($this->origin->id, $link->originConsenter->id);
        $this->assertSame($this->destination->id, $link->destinationConsenter->id);
        $this->assertSame($this->admin->id, $link->approver->id);
        $this->assertSame(1, $this->origin->outgoingLinks()->count());
        $this->assertSame(1, $this->destination->incomingLinks()->count());
    }

    // ── Parcel handoffs ──────────────────────────────────────────────────────

    public function test_handoff_requires_an_active_approved_link(): void
    {
        $pending = $this->links->requestLink($this->origin, $this->destination);

        $this->assertValidationFailure(
            fn () => $this->requestedHandoff($this->parcel, $pending),
            'link',
        );

        $active = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $active);

        $this->assertSame(ParcelHandoffStatus::Requested, $handoff->status);
        $this->assertSame($this->parcel->id, $handoff->sellerOrder->id);
        $this->assertSame($active->id, $handoff->link->id);
        $this->assertSame($this->origin->id, $handoff->originProvider->id);
        $this->assertSame($this->destination->id, $handoff->destinationProvider->id);
        $this->assertSame($this->sendingHub->id, $handoff->sendingHub->id);
        $this->assertSame($this->receivingHub->id, $handoff->receivingHub->id);
        $this->assertSame($this->origin->id, (int) $handoff->requested_by_id);
        $this->assertSame('logistics', $handoff->requested_by_type);
        $this->assertNotNull($handoff->requested_at);
        $this->assertSame(1, $this->parcel->parcelHandoffs()->count());
    }

    public function test_handoff_providers_must_match_the_directed_link(): void
    {
        $link = $this->activeLink();

        $this->assertValidationFailure(
            fn () => $this->requestedHandoff($this->parcel, $link, [
                'origin_provider_id' => $this->destination->id,
                'destination_provider_id' => $this->origin->id,
                'sending_hub_id' => $this->receivingHub->id,
                'receiving_hub_id' => $this->sendingHub->id,
            ]),
            'link',
        );
    }

    public function test_handoff_hubs_must_be_owned_by_the_corresponding_provider(): void
    {
        $link = $this->activeLink();

        $this->assertValidationFailure(
            fn () => $this->requestedHandoff($this->parcel, $link, [
                'sending_hub_id' => $this->receivingHub->id,
            ]),
            'sending_hub_id',
        );

        $this->assertValidationFailure(
            fn () => $this->requestedHandoff($this->parcel, $link, [
                'receiving_hub_id' => $this->sendingHub->id,
            ]),
            'receiving_hub_id',
        );
    }

    public function test_handoff_hubs_must_be_operational(): void
    {
        $link = $this->activeLink();

        $this->sendingHub->forceFill([
            'approval_status' => LogisticsHub::APPROVAL_PENDING,
        ])->save();

        $this->assertValidationFailure(
            fn () => $this->requestedHandoff($this->parcel, $link),
            'sending_hub_id',
        );
    }

    public function test_handoff_status_requires_its_audit_events(): void
    {
        $link = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $link);

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill(['status' => ParcelHandoffStatus::Accepted])->save();
        }, 'accepted_at');

        $handoff->forceFill([
            'status' => ParcelHandoffStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by_type' => 'logistics',
            'accepted_by_id' => $this->destination->id,
        ])->save();

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill(['status' => ParcelHandoffStatus::InTransit])->save();
        }, 'dispatched_at');

        $handoff->forceFill([
            'status' => ParcelHandoffStatus::InTransit,
            'dispatched_at' => now(),
            'dispatched_by_type' => 'logistics',
            'dispatched_by_id' => $this->origin->id,
        ])->save();

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill(['status' => ParcelHandoffStatus::Received])->save();
        }, 'received_at');

        $handoff->forceFill([
            'status' => ParcelHandoffStatus::Received,
            'received_at' => now(),
            'received_by_type' => 'logistics',
            'received_by_id' => $this->destination->id,
        ])->save();

        $this->assertSame(ParcelHandoffStatus::Received, $handoff->fresh()->status);
        $this->assertNotNull($handoff->fresh()->received_at);
    }

    public function test_handoff_event_actor_must_be_the_expected_provider(): void
    {
        $link = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $link);

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill([
                'accepted_at' => now(),
                'accepted_by_type' => 'logistics',
                'accepted_by_id' => $this->origin->id,
            ])->save();
        }, 'accepted_by_id');
    }

    public function test_rejected_handoff_requires_a_reason(): void
    {
        $link = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $link);

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill([
                'status' => ParcelHandoffStatus::Rejected,
                'rejected_at' => now(),
                'rejected_by_type' => 'logistics',
                'rejected_by_id' => $this->destination->id,
            ])->save();
        }, 'rejection_reason');

        $handoff->forceFill([
            'status' => ParcelHandoffStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by_type' => 'logistics',
            'rejected_by_id' => $this->destination->id,
            'rejection_reason' => 'Destination hub cannot serve this barangay.',
        ])->save();

        $this->assertSame(ParcelHandoffStatus::Rejected, $handoff->fresh()->status);
    }

    public function test_handoff_route_is_immutable_and_historical_rows_cannot_be_deleted(): void
    {
        $link = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $link);

        $this->assertValidationFailure(function () use ($handoff): void {
            $handoff->forceFill(['receiving_hub_id' => $this->sendingHub->id])->save();
        }, 'handoff');

        $this->assertValidationFailure(fn () => $handoff->delete(), 'handoff');

        $this->assertDatabaseHas('parcel_handoffs', ['id' => $handoff->id]);
    }

    public function test_only_one_active_handoff_is_allowed_per_seller_order(): void
    {
        $link = $this->activeLink();
        $this->requestedHandoff($this->parcel, $link);

        $this->assertSame(
            $this->parcel->id,
            (int) DB::table('parcel_handoffs')->where('seller_order_id', $this->parcel->id)->value('active_seller_order_id'),
        );

        try {
            $this->requestedHandoff($this->parcel, $link);
            $this->fail('A second active handoff was allowed for the same parcel.');
        } catch (QueryException) {
            // Expected: the generated active_seller_order_id unique key rejects it.
        }

        $this->assertSame(1, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());
    }

    public function test_terminal_handoffs_release_the_parcel_and_history_is_preserved(): void
    {
        $link = $this->activeLink();

        $first = $this->requestedHandoff($this->parcel, $link);
        $first->forceFill([
            'status' => ParcelHandoffStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by_type' => 'logistics',
            'rejected_by_id' => $this->destination->id,
            'rejection_reason' => 'Capacity unavailable.',
        ])->save();

        $this->assertNull(DB::table('parcel_handoffs')->where('id', $first->id)->value('active_seller_order_id'));

        $second = $this->requestedHandoff($this->parcel, $link);
        $second->forceFill([
            'status' => ParcelHandoffStatus::Received,
            'accepted_at' => now(),
            'accepted_by_type' => 'logistics',
            'accepted_by_id' => $this->destination->id,
            'dispatched_at' => now(),
            'dispatched_by_type' => 'logistics',
            'dispatched_by_id' => $this->origin->id,
            'received_at' => now(),
            'received_by_type' => 'logistics',
            'received_by_id' => $this->destination->id,
        ])->save();

        $this->assertNull(DB::table('parcel_handoffs')->where('id', $second->id)->value('active_seller_order_id'));

        $third = $this->requestedHandoff($this->parcel, $link);

        $this->assertSame(3, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());
        $this->assertSame(ParcelHandoffStatus::Rejected, $first->fresh()->status);
        $this->assertSame(ParcelHandoffStatus::Received, $second->fresh()->status);
        $this->assertSame(ParcelHandoffStatus::Requested, $third->status);
    }

    public function test_one_tracking_number_is_preserved_across_handoffs(): void
    {
        $link = $this->activeLink();
        $handoff = $this->requestedHandoff($this->parcel, $link);

        $this->assertFalse(Schema::hasColumn('parcel_handoffs', 'tracking_number'));
        $this->assertSame('TRK-PHASE51-0001', $this->parcel->fresh()->tracking_number);
        $this->assertSame($this->parcel->tracking_number, $handoff->sellerOrder->tracking_number);

        $handoff->forceFill([
            'status' => ParcelHandoffStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by_type' => 'logistics',
            'rejected_by_id' => $this->destination->id,
            'rejection_reason' => 'Not serviceable.',
        ])->save();
        $this->requestedHandoff($this->parcel, $link);

        $this->assertSame('TRK-PHASE51-0001', $this->parcel->fresh()->tracking_number);
        $this->assertSame(1, SellerOrder::query()->where('tracking_number', 'TRK-PHASE51-0001')->count());
    }

    public function test_duplicate_seller_order_tracking_numbers_are_rejected(): void
    {
        $this->expectException(QueryException::class);

        SellerOrder::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'seller_id' => Seller::factory()->create()->id,
            'tracking_number' => 'TRK-PHASE51-0001',
        ]);
    }

    public function test_foreign_keys_restrict_deletion_of_handoff_history(): void
    {
        $link = $this->activeLink();
        $this->requestedHandoff($this->parcel, $link);

        try {
            DB::table('seller_orders')->where('id', $this->parcel->id)->delete();
            $this->fail('A seller_order with handoff history was deleted.');
        } catch (QueryException) {
            // Expected: RESTRICT foreign key.
        }

        $this->assertDatabaseHas('seller_orders', ['id' => $this->parcel->id]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function provider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'province_verified_at' => now(),
        ]);
    }

    private function provinceHub(LogisticsProvider $provider, string $name, string $municipality): LogisticsHub
    {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $name,
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'municipality' => $municipality,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);
    }

    private function stationHub(LogisticsProvider $provider, LogisticsHub $province, string $name): LogisticsHub
    {
        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $name,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => '1 Coverage Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }

    private function coverage(LogisticsProvider $provider, LogisticsHub $station): Area
    {
        return Area::query()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'name' => 'Operational coverage',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'coverage_validated_at' => now(),
            'is_active' => true,
        ]);
    }

    private function parcel(string $trackingNumber): SellerOrder
    {
        return SellerOrder::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'seller_id' => Seller::factory()->create()->id,
            'tracking_number' => $trackingNumber,
        ]);
    }

    private function activeLink(): LogisticsProviderLink
    {
        $link = $this->links->requestLink($this->origin, $this->destination);
        $this->links->consent($link, $this->destination);

        return $this->links->approve($link, $this->admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function requestedHandoff(
        SellerOrder $parcel,
        LogisticsProviderLink $link,
        array $overrides = [],
    ): ParcelHandoff {
        $handoff = new ParcelHandoff;
        $handoff->forceFill(array_merge([
            'seller_order_id' => $parcel->id,
            'logistics_provider_link_id' => $link->id,
            'origin_provider_id' => $link->origin_provider_id,
            'destination_provider_id' => $link->destination_provider_id,
            'sending_hub_id' => $this->sendingHub->id,
            'receiving_hub_id' => $this->receivingHub->id,
            'status' => ParcelHandoffStatus::Requested->value,
            'requested_at' => now(),
            'requested_by_type' => 'logistics',
            'requested_by_id' => $link->origin_provider_id,
        ], $overrides))->save();

        return $handoff;
    }

    private function assertValidationFailure(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), "Expected a validation error on [{$field}].");

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}

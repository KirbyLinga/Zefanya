<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\Admin;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\TransportConnection;
use App\Services\Logistics\ProviderHandoffSchemaService;
use App\Services\Logistics\RoutePlannerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class TransportConnectionManagementTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private function network(): array
    {
        $this->withoutVite();
        $origin = $this->operationalHandoffProvider('Origin Transport', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $destination = $this->operationalHandoffProvider('Destination Transport', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'NCR');

        return [$origin, $destination];
    }

    private function payload(array $origin, ?array $destination = null, string $mode = 'ROAD'): array
    {
        return [
            'origin_hub_id' => $origin['station']->id,
            'destination_hub_id' => ($destination ?? $origin)['province']->id,
            'transport_mode' => $mode,
        ];
    }

    private function connect(array $origin, ?array $destination = null): TransportConnection
    {
        return TransportConnection::query()->create($this->payload($origin, $destination) + [
            'operating_provider_id' => $origin['provider']->id,
            'is_active' => true,
        ]);
    }

    #[TestWith(['ROAD'])]
    #[TestWith(['SEA'])]
    #[TestWith(['AIR'])]
    public function test_staff_create_internal_connections_in_each_mode(string $mode): void
    {
        [$origin] = $this->network();

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin, mode: $mode))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transport_connections', $this->payload($origin, mode: $mode) + [
            'operating_provider_id' => $origin['provider']->id, 'is_active' => true,
        ]);
        $this->get(route('logistics.transport-connections.index'))
            ->assertOk()->assertSee('Internal')->assertSee('Deactivate')->assertSee('Origin Transport');
    }

    public function test_staff_create_cross_provider_connection_with_approved_directional_link(): void
    {
        [$origin, $destination] = $this->network();
        $this->activeHandoffLink($origin['provider'], $destination['provider'], Admin::factory()->create());

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin, $destination))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transport_connections', $this->payload($origin, $destination));
        $this->get(route('logistics.transport-connections.index'))
            ->assertOk()->assertSee('Cross-provider')->assertSee($destination['province']->name);
    }

    public function test_cross_provider_connection_requires_approved_link(): void
    {
        [$origin, $destination] = $this->network();

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin, $destination))
            ->assertSessionHasErrors('destination_hub_id');

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_pending_link_does_not_authorize_connection(): void
    {
        [$origin, $destination] = $this->network();
        app(ProviderHandoffSchemaService::class)->requestLink($origin['provider'], $destination['provider']);

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin, $destination))
            ->assertSessionHasErrors('destination_hub_id');

        $this->assertDatabaseCount('transport_connections', 0);
    }

    #[TestWith(['station', 'origin_hub_id'])]
    #[TestWith(['province', 'origin_hub_id'])]
    public function test_inactive_hub_is_rejected(string $hub, string $field): void
    {
        [$origin] = $this->network();
        $origin[$hub]->update(['is_active' => false]);

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_unrelated_staff_cannot_create_or_modify_connections(): void
    {
        [$origin, $other] = $this->network();
        $connection = $this->connect($origin);

        $this->actingAs($other['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin))->assertForbidden();
        $this->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => false])->assertNotFound();
        $this->get(route('logistics.transport-connections.index'))->assertDontSee($origin['station']->name);

        $this->assertTrue($connection->fresh()->is_active);
        $this->assertDatabaseCount('transport_connections', 1);
    }

    public function test_operator_cannot_be_forged(): void
    {
        [$origin, $other] = $this->network();

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin) + ['operating_provider_id' => $other['provider']->id])
            ->assertForbidden();

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_active_duplicate_is_rejected_but_another_mode_is_allowed(): void
    {
        [$origin] = $this->network();
        $this->connect($origin);
        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin))
            ->assertSessionHasErrors('destination_hub_id');
        $this->assertDatabaseCount('transport_connections', 1);

        $this->post(route('logistics.transport-connections.store'), $this->payload($origin, mode: 'AIR'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transport_connections', 2);
    }

    public function test_self_connection_is_rejected(): void
    {
        [$origin] = $this->network();
        $payload = $this->payload($origin);
        $payload['destination_hub_id'] = $payload['origin_hub_id'];

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $payload)->assertSessionHasErrors('destination_hub_id');

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_connections_are_directional(): void
    {
        [$origin] = $this->network();
        $this->connect($origin);
        $planner = app(RoutePlannerService::class);

        $this->assertCount(1, $planner->findPath($origin['station'], $origin['province'], $origin['provider']));
        $this->assertSame([], $planner->findPath($origin['province'], $origin['station'], $origin['provider']));
    }

    public function test_deactivation_blocks_new_paths_and_reactivation_restores_them(): void
    {
        [$origin] = $this->network();
        $connection = $this->connect($origin);
        $planner = app(RoutePlannerService::class);
        $this->actingAs($origin['provider'], 'logistics')
            ->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $planner->findPath($origin['station'], $origin['province'], $origin['provider']));
        $this->get(route('logistics.transport-connections.index'))->assertSee('Activate');

        $this->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertCount(1, $planner->findPath($origin['station'], $origin['province'], $origin['provider']));
    }

    public function test_revoked_link_blocks_existing_connection_even_with_origin_operator(): void
    {
        [$origin, $destination] = $this->network();
        $admin = Admin::factory()->create();
        $link = $this->activeHandoffLink($origin['provider'], $destination['provider'], $admin);
        $this->connect($origin, $destination);
        $planner = app(RoutePlannerService::class);
        $this->assertCount(1, $planner->findPath($origin['station'], $destination['province'], $origin['provider']));

        app(ProviderHandoffSchemaService::class)->revoke($link, $admin, 'Transport cooperation withdrawn.');

        $this->assertSame([], $planner->findPath($origin['station'], $destination['province'], $origin['provider']));
    }

    public function test_same_station_route_requires_no_connection(): void
    {
        [$origin] = $this->network();
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');

        $route = app(RoutePlannerService::class)->planRoute($seller, $origin['provider'], '0305401001', '030540000');

        $this->assertSame([], $route->legs);
        $this->assertSame($route->origin_hub_id, $route->destination_hub_id);
        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_disabling_connection_preserves_saved_route_and_requires_review(): void
    {
        [$origin] = $this->network();
        $connection = $this->connect($origin);
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $buyer = $this->buyerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $parcel = $this->handoffParcel($seller, $buyer, $origin['provider']);
        $planner = app(RoutePlannerService::class);
        $legs = $planner->findPath($origin['station'], $origin['province'], $origin['provider']);
        $route = ParcelRoute::query()->create([
            'seller_order_id' => $parcel->id, 'origin_hub_id' => $origin['station']->id,
            'destination_hub_id' => $origin['province']->id, 'legs' => $legs, 'current_leg_index' => 0,
        ]);

        $legs = $route->fresh()->legs;

        $this->actingAs($origin['provider'], 'logistics')
            ->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame($legs, $route->fresh()->legs);
        $this->assertSame(0, $route->fresh()->current_leg_index);
        $this->assertFalse($planner->isRouteFeasible($route));
        $this->expectException(ValidationException::class);
        $planner->requireAvailableFutureLegs($route, 0);
    }

    public function test_deactivation_remains_possible_after_hub_becomes_inactive(): void
    {
        [$origin] = $this->network();
        $connection = $this->connect($origin);
        $origin['station']->update(['is_active' => false]);

        $this->actingAs($origin['provider'], 'logistics')
            ->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($connection->fresh()->is_active);
        $this->patch(route('logistics.transport-connections.status', $connection->id), ['is_active' => true])
            ->assertSessionHasErrors('origin_hub_id');
    }

    public function test_reactivation_cannot_duplicate_a_replacement_connection(): void
    {
        [$origin] = $this->network();
        $old = $this->connect($origin);
        $old->update(['is_active' => false]);
        $this->connect($origin);

        $this->actingAs($origin['provider'], 'logistics')
            ->patch(route('logistics.transport-connections.status', $old->id), ['is_active' => true])
            ->assertSessionHasErrors('destination_hub_id');

        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_hub_outside_verified_province_is_rejected(): void
    {
        [$origin] = $this->network();
        // Simulate a legacy inconsistent row without bypassing the HTTP boundary under test.
        DB::table('logistics_hubs')->where('id', $origin['province']->id)
            ->update(['province_code' => '137404000']);

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin))
            ->assertSessionHasErrors('origin_hub_id');

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_reverse_provider_link_does_not_authorize_forward_connection(): void
    {
        [$origin, $destination] = $this->network();
        $this->activeHandoffLink($destination['provider'], $origin['provider'], Admin::factory()->create());

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $this->payload($origin, $destination))
            ->assertSessionHasErrors('destination_hub_id');

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_missing_hub_and_invalid_mode_are_rejected(): void
    {
        [$origin] = $this->network();
        $payload = $this->payload($origin);
        $payload['destination_hub_id'] = 999999;
        $payload['transport_mode'] = 'RAIL';

        $this->actingAs($origin['provider'], 'logistics')
            ->post(route('logistics.transport-connections.store'), $payload)
            ->assertSessionHasErrors(['destination_hub_id', 'transport_mode']);

        $this->assertDatabaseCount('transport_connections', 0);
    }

    public function test_suspended_operator_blocks_new_planning(): void
    {
        [$origin] = $this->network();
        $this->connect($origin);
        $origin['provider']->forceFill(['status' => 'suspended'])->save();

        $this->assertSame([], app(RoutePlannerService::class)->findPath(
            $origin['station']->fresh(), $origin['province']->fresh(), $origin['provider']->fresh(),
        ));
    }

    public function test_guest_and_buyer_cannot_manage_transport_connections(): void
    {
        $this->get(route('logistics.transport-connections.index'))->assertRedirect();
        [$origin] = $this->network();
        $buyer = $this->buyerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');

        $this->actingAs($buyer, 'buyer')->post(route('logistics.transport-connections.store'), $this->payload($origin))->assertRedirect();

        $this->assertDatabaseCount('transport_connections', 0);
    }
}

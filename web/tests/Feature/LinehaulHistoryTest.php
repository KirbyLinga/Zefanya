<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDecline;
use App\Models\Logistics\Manifest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulHistoryTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $network = $this->operationalHandoffProvider('History Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'History Courier', 'email' => 'history@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();

        return [...$network, 'driver' => $driver];
    }

    private function trip(array $f, ManifestStatus $status = ManifestStatus::Received, string $at = '2026-11-08T12:00:00+08:00'): LinehaulAssignment
    {
        $time = \Carbon\Carbon::parse($at)->setTimezone(config('app.timezone'));
        $manifest = new Manifest;
        $manifest->forceFill(['logistics_provider_id' => $f['provider']->id, 'origin_hub_id' => $f['station']->id,
            'destination_hub_id' => $f['province']->id, 'status' => $status,
            'received_at' => $status === ManifestStatus::Received || $status === ManifestStatus::ReceivedWithExceptions ? $time : null,
            'cancelled_at' => $status === ManifestStatus::Cancelled ? $time : null])->save();
        $assignment = new LinehaulAssignment;
        $assignment->forceFill(['manifest_id' => $manifest->id, 'linehaul_driver_id' => $f['driver']->id])->save();

        return $assignment;
    }

    private function decline(array $f): LinehaulTripDecline
    {
        $decline = new LinehaulTripDecline;
        $decline->forceFill(['manifest_id' => 999999, 'logistics_provider_id' => $f['provider']->id, 'linehaul_driver_id' => $f['driver']->id,
            'offer_version' => 1, 'reason' => 'Vehicle problem', 'created_at' => \Carbon\Carbon::parse('2026-11-08T12:00:00+08:00')->setTimezone(config('app.timezone')),
            'snapshot' => ['origin' => 'Original origin', 'destination' => 'Original destination', 'origin_address' => 'Old origin address',
                'destination_address' => 'Old destination address', 'parcel_count' => 5]])->save();

        return $decline;
    }

    private function api($driver): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($driver->createToken('history-test', ['linehaul'])->plainTextToken);
    }

    private const URL = '/api/courier/history?from=2026-11-08&to=2026-11-08';

    public function test_history_auth_validation_identity_overrides_and_throttle(): void
    {
        $f = $this->fixture();
        $this->getJson(self::URL)->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->getJson(self::URL)->assertForbidden();
        $this->api($f['driver']);
        foreach (['driver_id', 'courier_id', 'logistics_provider_id', 'id'] as $field) $this->getJson(self::URL.'&'.$field.'=99')->assertUnprocessable();
        foreach (['from=bad&to=2026-11-08', 'from=2026-11-09&to=2026-11-08', 'from=2026-11-08', 'from=2025-01-01&to=2026-11-08', 'page=0'] as $query) {
            $this->getJson('/api/courier/history?'.$query)->assertUnprocessable();
        }
        $this->getJson(self::URL)->assertOk();
    }

    public function test_history_shared_throttle_applies(): void
    {
        $f = $this->fixture(); $this->api($f['driver']);
        for ($i = 0; $i < 60; $i++) $this->getJson('/api/courier/history?page=0')->assertUnprocessable();
        $this->getJson(self::URL)->assertStatus(429);
    }

    public function test_history_includes_all_terminal_results_and_deleted_draft_decline_snapshot(): void
    {
        $f = $this->fixture();
        foreach ([ManifestStatus::Received, ManifestStatus::ReceivedWithExceptions, ManifestStatus::Cancelled, ManifestStatus::Draft, ManifestStatus::Dispatched] as $status) $this->trip($f, $status);
        $this->decline($f);
        $this->api($f['driver'])->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.total', 4)->assertJsonCount(4, 'data.trips')->assertSee('Original destination')->assertSee('Vehicle problem');
        $statuses = $this->getJson(self::URL)->json('data.trips.*.status');
        $this->assertEqualsCanonicalizing(['Completed', 'Received with Issues', 'Cancelled', 'Declined'], $statuses);
        $this->assertDatabaseMissing('manifests', ['id' => 999999]);
    }

    public function test_history_date_boundaries_are_inclusive_philippine_dates(): void
    {
        $f = $this->fixture();
        foreach (['2026-11-07T23:59:59+08:00', '2026-11-08T00:00:00+08:00', '2026-11-08T23:59:59+08:00', '2026-11-09T00:00:00+08:00'] as $date) $this->trip($f, ManifestStatus::Received, $date);
        $this->api($f['driver'])->getJson(self::URL)->assertOk()->assertJsonPath('data.total', 2)->assertJsonCount(2, 'data.trips');
    }

    public function test_history_reaches_records_beyond_recent_fifty_and_other_courier_sees_neither_rows_nor_counts(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 51; $i++) $this->trip($f);
        $this->decline($f);
        $before = [LinehaulAssignment::get()->toArray(), LinehaulTripDecline::get()->toArray()];
        $this->api($f['driver']);
        $keys = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson(self::URL.'&page='.$page)->assertOk()->assertJsonPath('data.total', 52)->assertJsonPath('data.last_page', 3);
            $keys = [...$keys, ...$response->json('data.trips.*.history_key')];
        }
        $this->assertCount(52, array_unique($keys));
        $this->assertSame($before, [LinehaulAssignment::get()->toArray(), LinehaulTripDecline::get()->toArray()]);
        $other = $f['driver']->replicate()->forceFill(['email' => 'other-history@example.com']); $other->save();
        $this->api($other)->getJson(self::URL)->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.trips');
    }

    public function test_history_company_filter_protects_candidate_counts_and_response_rows(): void
    {
        $f = $this->fixture();
        $other = $this->operationalHandoffProvider('Other History Carrier', '033000000', 'Bulacan', '033014000', 'Malolos', '033014001', 'Atlag', '030000000', 'Central Luzon');
        $this->trip([...$other, 'driver' => $f['driver']]);
        $this->decline([...$other, 'driver' => $f['driver']]);
        $this->api($f['driver'])->getJson(self::URL)->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.trips');
    }
}

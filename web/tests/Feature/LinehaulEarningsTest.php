<?php

namespace Tests\Feature;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\Manifest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulEarningsTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): array
    {
        $network = $this->operationalHandoffProvider('Earnings Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Earnings Courier', 'email' => 'earnings@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();

        return [...$network, 'driver' => $driver];
    }

    private function completed(array $f, string $at, ?int $pay = 100000): LinehaulAssignment
    {
        $manifest = new Manifest;
        $manifest->forceFill(['logistics_provider_id' => $f['provider']->id, 'origin_hub_id' => $f['station']->id,
            'destination_hub_id' => $f['province']->id, 'status' => ManifestStatus::Received, 'received_at' => $at,
            'received_by_type' => 'logistics', 'received_by_id' => $f['provider']->id,
            'dispatched_at' => $at, 'dispatched_by_type' => 'linehaul', 'dispatched_by_id' => $f['driver']->id])->save();
        $assignment = new LinehaulAssignment;
        $assignment->forceFill(['manifest_id' => $manifest->id, 'linehaul_driver_id' => $f['driver']->id,
            'trip_pay_minor' => $pay, 'trip_pay_vehicle_type' => $pay === null ? null : '4-Wheeler Closed Van Truck',
            'seal_receipt' => ['mismatch' => false]])->save();

        return $assignment;
    }

    private function api($driver): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withToken($driver->createToken('earnings-test', ['linehaul'])->plainTextToken);
    }

    public function test_earnings_requires_real_courier_and_prohibits_identity_overrides(): void
    {
        $f = $this->fixture();
        $this->getJson('/api/courier/earnings')->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->getJson('/api/courier/earnings')->assertForbidden();
        $this->api($f['driver']);
        foreach (['driver_id', 'courier_id', 'logistics_provider_id', 'id'] as $field) {
            $this->getJson('/api/courier/earnings?'.$field.'=999')->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson('/api/courier/earnings')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_inclusive_philippine_boundaries_integer_total_and_unknown_pay_are_honest(): void
    {
        $f = $this->fixture();
        $zone = config('app.timezone');
        foreach (['2026-11-07T23:59:59+08:00', '2026-11-08T00:00:00+08:00', '2026-11-08T23:59:59+08:00', '2026-11-09T00:00:00+08:00'] as $date) {
            $this->completed($f, \Carbon\Carbon::parse($date)->setTimezone($zone)->toDateTimeString());
        }
        $this->completed($f, \Carbon\Carbon::parse('2026-11-08T12:00:00+08:00')->setTimezone($zone)->toDateTimeString(), null);
        $this->api($f['driver'])->getJson('/api/courier/earnings?from=2026-11-08&to=2026-11-08')->assertOk()
            ->assertJsonPath('data.earned_minor', 200000)->assertJsonPath('data.earned_trips', 2)
            ->assertJsonPath('data.completed_trips', 3)->assertJsonPath('data.unconfirmed_trips', 1)->assertJsonCount(3, 'data.trips');
    }

    public function test_total_covers_all_pages_repeat_reads_do_not_credit_and_other_courier_cannot_see_pay(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 21; $i++) $this->completed($f, '2026-11-08 04:00:00');
        $before = LinehaulAssignment::get()->toArray();
        $this->api($f['driver']);
        $url = '/api/courier/earnings?from=2026-11-08&to=2026-11-08';
        $this->getJson($url)->assertOk()->assertJsonPath('data.earned_minor', 2100000)->assertJsonPath('data.last_page', 2)->assertJsonCount(20, 'data.trips');
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.earned_minor', 2100000)->assertJsonCount(1, 'data.trips');
        $this->assertSame($before, LinehaulAssignment::get()->toArray());
        $other = $f['driver']->replicate()->forceFill(['email' => 'other-earnings@example.com']); $other->save();
        $this->api($other)->getJson($url)->assertOk()->assertJsonPath('data.earned_minor', 0)->assertJsonCount(0, 'data.trips');
    }

    public function test_date_range_and_page_validation_and_shared_throttle(): void
    {
        $f = $this->fixture(); $this->api($f['driver']);
        foreach (['from=bad&to=2026-11-08', 'from=2026-11-09&to=2026-11-08', 'from=2026-11-08',
            'from=2025-01-01&to=2026-11-08', 'page=0', 'page=1.5'] as $query) {
            $this->getJson('/api/courier/earnings?'.$query)->assertUnprocessable();
        }
        for ($i = 0; $i < 54; $i++) $this->getJson('/api/courier/earnings?page=0')->assertUnprocessable();
        $this->getJson('/api/courier/earnings')->assertStatus(429);
    }

    public function test_company_scope_and_unconfirmed_handover_cannot_inflate_total(): void
    {
        $f = $this->fixture();
        $otherNetwork = $this->operationalHandoffProvider('Other Earnings Carrier', '033000000', 'Bulacan', '033014000', 'Malolos', '033014001', 'Atlag', '030000000', 'Central Luzon');
        $this->completed([...$otherNetwork, 'driver' => $f['driver']], '2026-11-08 04:00:00');
        $own = $this->completed($f, '2026-11-08 04:00:00');
        $own->manifest->forceFill(['received_by_type' => 'linehaul'])->save();
        $this->api($f['driver'])->getJson('/api/courier/earnings?from=2026-11-08&to=2026-11-08')->assertOk()
            ->assertJsonPath('data.earned_minor', 0)->assertJsonPath('data.completed_trips', 1)->assertJsonPath('data.unconfirmed_trips', 1);
    }
}

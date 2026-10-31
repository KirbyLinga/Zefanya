<?php

namespace Tests\Feature\Rider;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use Database\Seeders\DemoRidersSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoRidersSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seedDemoRiders(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DemoRidersSeeder::class]));
    }

    public function test_seeder_creates_the_expected_riders_per_provider(): void
    {
        $this->seedDemoRiders();

        $providerA = LogisticsProvider::where('email', DemoRidersSeeder::PROVIDER_A_EMAIL)->firstOrFail();
        $providerB = LogisticsProvider::where('email', DemoRidersSeeder::PROVIDER_B_EMAIL)->firstOrFail();

        $this->assertSame('approved', $providerA->status->value);
        $this->assertSame('approved', $providerB->status->value);
        $this->assertSame(3, Courier::where('logistics_provider_id', $providerA->id)->count());
        $this->assertSame(2, Courier::where('logistics_provider_id', $providerB->id)->count());

        $this->assertDatabaseHas('couriers', [
            'phone' => '09170000011',
            'logistics_provider_id' => $providerA->id,
            'is_available' => true,
        ]);
        $this->assertDatabaseHas('couriers', [
            'phone' => '09170000013',
            'logistics_provider_id' => $providerA->id,
            'is_available' => false,
        ]);
        $this->assertDatabaseHas('couriers', [
            'phone' => '09170000022',
            'logistics_provider_id' => $providerB->id,
            'is_available' => true,
        ]);
        $this->assertSame(['Santa Cruz'], Courier::where('phone', '09170000011')->firstOrFail()->service_area);
        $this->assertSame(['Los Baños'], Courier::where('phone', '09170000022')->firstOrFail()->service_area);
        $this->assertTrue(Courier::where('logistics_provider_id', $providerA->id)->get()->every(
            fn (Courier $courier): bool => $courier->rider !== null && $courier->isEligibleForAssignment()
        ));
    }

    public function test_seeder_is_idempotent_on_a_second_run(): void
    {
        $this->seedDemoRiders();
        $this->seedDemoRiders();

        $this->assertSame(2, LogisticsProvider::whereIn('email', [
            DemoRidersSeeder::PROVIDER_A_EMAIL,
            DemoRidersSeeder::PROVIDER_B_EMAIL,
        ])->count());
        $this->assertSame(5, Courier::whereIn('phone', [
            '09170000011',
            '09170000012',
            '09170000013',
            '09170000021',
            '09170000022',
        ])->count());
    }

    public function test_unavailable_rider_is_not_offered_by_the_assignment_query(): void
    {
        $this->seedDemoRiders();

        $providerA = LogisticsProvider::where('email', DemoRidersSeeder::PROVIDER_A_EMAIL)->firstOrFail();
        $availableInLosBanos = Courier::query()
            ->where('logistics_provider_id', $providerA->id)
            ->where('is_available', true)
            ->get()
            ->filter(fn (Courier $courier) => $courier->servesMunicipality('Los Baños'));

        $this->assertTrue($availableInLosBanos->isEmpty());
    }
}

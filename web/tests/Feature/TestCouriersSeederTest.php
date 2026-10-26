<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use Database\Seeders\TestCouriersSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TestCouriersSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seedTestCouriers(): void
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => TestCouriersSeeder::class]));
    }

    private function createOwnedProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'email' => TestCouriersSeeder::PROVIDER_EMAIL,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    public function test_it_skips_cleanly_when_the_provider_does_not_exist(): void
    {
        $this->seedTestCouriers();

        $this->assertSame(0, Courier::count());
    }

    public function test_it_seeds_available_riders_for_the_provider(): void
    {
        $provider = $this->createOwnedProvider();

        $this->seedTestCouriers();

        $riders = Courier::where('logistics_provider_id', $provider->id)->get();

        $this->assertCount(3, $riders);
        $this->assertTrue($riders->every(fn (Courier $courier): bool => $courier->is_available));
        $this->assertTrue($riders->every(fn (Courier $courier): bool => $courier->servesMunicipality('Plaridel')));
        $this->assertTrue($riders->every(fn (Courier $courier): bool => $courier->servesMunicipality('Quezon City')));
        $this->assertTrue($riders->every(fn (Courier $courier): bool => $courier->rider !== null && $courier->isEligibleForAssignment()));
        $this->assertTrue($riders->every(fn (Courier $courier): bool => $courier->rider->station_hub_id !== null));
        $this->assertSame(3, Rider::where('logistics_provider_id', $provider->id)->count());
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        $provider = $this->createOwnedProvider();

        $this->seedTestCouriers();
        $this->seedTestCouriers();

        $this->assertSame(3, Courier::where('logistics_provider_id', $provider->id)->count());
    }

    public function test_legacy_seeded_coverage_cannot_assign_without_barangay_configuration(): void
    {
        $provider = $this->createOwnedProvider();

        $this->seedTestCouriers();

        $order = Order::factory()->create([
            'shipping_address' => [
                'municipality' => 'Plaridel',
                'province' => 'Bulacan',
            ],
        ]);

        $sellerOrder = SellerOrder::factory()->create(['order_id' => $order->id]);
        $sellerOrder->forceFill(['status' => OrderStatus::ReadyForPickup->value])->save();

        $assigned = (new CourierAssignmentService)->assignPickup($sellerOrder->fresh(), $provider);

        $this->assertNull($assigned, 'Municipality-only seed coverage must not route new assignments');
        $this->assertNull($sellerOrder->fresh()->courier_id);
    }

    public function test_re_running_does_not_free_a_rider_still_holding_a_parcel(): void
    {
        $provider = $this->createOwnedProvider();

        $this->seedTestCouriers();

        $courier = Courier::where('logistics_provider_id', $provider->id)->firstOrFail();
        $courier->forceFill(['is_available' => false])->save();

        // A parcel currently in the delivery leg, held by this rider.
        $order = Order::factory()->create(['shipping_address' => ['municipality' => 'Plaridel']]);
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'delivery_courier_id' => $courier->id,
        ]);
        $sellerOrder->forceFill(['status' => OrderStatus::AssignedToRider->value])->save();

        $this->seedTestCouriers();

        $this->assertFalse($courier->fresh()->is_available, 'An in-flight rider stays unavailable');
    }
}

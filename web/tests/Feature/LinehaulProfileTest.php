<?php

namespace Tests\Feature;

use App\Models\Logistics\LinehaulDriver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulProfileTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function driver(): LinehaulDriver
    {
        $network = $this->operationalHandoffProvider('Profile Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Profile Courier', 'email' => 'profile@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();

        return $driver;
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('profile', ['linehaul'])->plainTextToken);
    }

    public function test_profile_is_own_allowlisted_company_data_and_not_cacheable(): void
    {
        $driver = $this->driver();
        $other = $driver->replicate();
        $other->forceFill(['name' => 'PRIVATE OTHER COURIER', 'email' => 'private@example.com'])->save();
        $this->api($driver)->getJson('/api/courier/profile')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['success' => true, 'message' => 'Courier profile.', 'errors' => null,
                'data' => ['id' => $driver->id, 'name' => $driver->name, 'email' => $driver->email,
                    'status' => 'active', 'email_verified' => true,
                    'company' => ['name' => $driver->provider->business_name],
                    'home_hub' => ['name' => $driver->homeHub->name]]]);
        foreach (['id', 'driver_id', 'courier_id', 'logistics_provider_id', 'home_hub_id'] as $field) {
            $this->getJson('/api/courier/profile?'.$field.'='.$other->id)->assertUnprocessable()
                ->assertJsonValidationErrors($field)->assertDontSee($other->name);
        }
        $this->getJson('/api/courier/profile/'.$other->id)->assertNotFound();
        $this->assertSame('PRIVATE OTHER COURIER', $other->fresh()->name);
    }

    public function test_profile_requires_authentic_courier_and_active_company_and_home_hub(): void
    {
        $driver = $this->driver();
        $this->getJson('/api/courier/profile')->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)
            ->getJson('/api/courier/profile')->assertForbidden();
        $this->api($driver);
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/courier/profile')->assertForbidden();
        $driver->forceFill(['status' => 'active'])->save();
        $driver->provider->forceFill(['archived_at' => now()])->save();
        $this->api($driver)->getJson('/api/courier/profile')->assertForbidden();
        $driver->provider->forceFill(['archived_at' => null])->save();
        $driver->homeHub->forceFill(['is_active' => false])->save();
        $this->api($driver)->getJson('/api/courier/profile')->assertForbidden();
    }

    public function test_profile_shares_the_authenticated_request_limit(): void
    {
        $driver = $this->driver();
        $this->api($driver);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/courier/profile')->assertOk();
        }

        $this->getJson('/api/courier/profile')->assertStatus(429);
    }
}

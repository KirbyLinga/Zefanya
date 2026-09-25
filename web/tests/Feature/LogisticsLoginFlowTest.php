<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LogisticsLoginFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_approved_logistics_provider_redirects_to_dashboard_after_login(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'email' => 'claire@example.com',
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
        ]);

        $response = $this->post(route('unified.login'), [
            'email' => $provider->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('logistics.dashboard'));
        $this->assertAuthenticated('logistics');

        $this->get(route('logistics.dashboard'))->assertOk();
    }

    public function test_logistics_guard_tried_before_buyer_guard_with_same_email(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'email' => 'shared@example.com',
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
        ]);

        Buyer::factory()->create([
            'email' => 'shared@example.com',
            'password' => 'password',
        ]);

        $response = $this->post(route('unified.login'), [
            'email' => 'shared@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('logistics.dashboard'));
        $this->assertAuthenticated('logistics');
        $this->assertGuest('buyer');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the logistics dashboard (seller-mirrored layout) renders for an
 * approved logistics provider, is gated correctly for everyone else, and
 * shows honest empty states — no fake operational numbers, since no
 * courier/parcel/delivery tables exist yet.
 */
class LogisticsDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedLogisticsProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'email' => 'claire@example.com',
            'business_name' => 'Cruz Logistics Services',
            'status' => LogisticsProviderStatus::Approved->value,
            'email_verified_at' => now(),
        ]);
    }

    private function pendingApprovalProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->pendingApproval()->create();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('logistics.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_non_approved_provider_is_bounced_to_login(): void
    {
        $this->actingAs($this->pendingApprovalProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_renders_for_an_approved_provider(): void
    {
        $provider = $this->approvedLogisticsProvider();

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, '.$provider->business_name)
            ->assertSee('data-theme-toggle', true);
    }

    public function test_dashboard_shows_honest_empty_states_without_fake_numbers(): void
    {
        $html = $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->getContent();

        // Empty states are shown for every panel that has no backing data.
        $this->assertStringContainsString('No delivery data yet', $html);
        $this->assertStringContainsString('No applications yet', $html);
        $this->assertStringContainsString('No parcels yet', $html);

        // Stat cards render the null placeholder, never a fabricated value.
        $this->assertStringContainsString('Parcels in hub', $html);
        $this->assertStringNotContainsString('ZEF-9824', $html);
        $this->assertStringNotContainsString('842 parcels', $html);
    }

    public function test_sidebar_links_only_to_wired_routes_without_fake_badges(): void
    {
        $html = $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->getContent();

        // The one wired route renders as a real link; future areas stay inert.
        $this->assertStringContainsString(route('logistics.dashboard'), $html);
        $this->assertStringContainsString('Courier Applications', $html);

        // The old stub's fabricated nav badges are gone.
        $this->assertStringNotContainsString('8 new', $html);
        $this->assertStringNotContainsString('lg-nav-badge', $html);
    }
}

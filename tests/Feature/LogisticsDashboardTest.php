<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the logistics "Sorting Center" shell renders for an approved
 * logistics provider and is gated correctly for everyone else.
 */
class LogisticsDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedLogisticsProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'email' => 'claire@example.com',
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
        $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('Sorting Center', true)
            ->assertSee('Hub 04', true)
            ->assertSee('ZEF-982410', true)
            ->assertSee('ZEF-982415', true)
            ->assertSee('842 parcels', true)
            ->assertSee('data-theme-toggle', true);
    }

    public function test_status_pills_cover_all_variants(): void
    {
        $html = $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->getContent();

        foreach (['Delivered', 'Sorting', 'Pending Approval', 'Pending', 'In Transit'] as $label) {
            $this->assertStringContainsString($label, $html, "Status label [{$label}] missing.");
        }
    }
}

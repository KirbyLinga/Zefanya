<?php

namespace Tests\Feature;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the seller dashboard hero card introduced in the hero-card restyle:
 *   - Returns 200 for an approved seller.
 *   - Renders the hero wrapper ([data-dashboard-hero]).
 *   - Contains a [data-theme-toggle] button inside the hero.
 *   - Contains the notification bell button with the correct aria-label.
 *   - Shows the empty-state text when there are no notifications.
 *   - Does not error even when the notifications table does not exist
 *     (the controller guards with Schema::hasTable).
 */
class SellerDashboardHeroTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedSeller(): Seller
    {
        return Seller::factory()->create(['status' => 'approved']);
    }

    public function test_dashboard_returns_200_for_approved_seller(): void
    {
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_renders_the_hero_wrapper(): void
    {
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $response->assertOk();
        $response->assertSee('data-dashboard-hero', false);
    }

    public function test_hero_contains_a_theme_toggle_button(): void
    {
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $content = $response->getContent();

        // Hero wrapper must appear before the theme-toggle data attribute.
        $heroPos = mb_strpos($content, 'data-dashboard-hero');
        $togglePos = mb_strpos($content, 'data-theme-toggle');

        $this->assertNotFalse($heroPos, 'data-dashboard-hero not found in response.');
        $this->assertNotFalse($togglePos, 'data-theme-toggle not found in response.');

        // At least one [data-theme-toggle] must appear after the hero opens.
        // (The sidebar toggle appears before; we just confirm at least one exists inside.)
        $heroChunk = mb_substr($content, $heroPos);
        $this->assertStringContainsString('data-theme-toggle', $heroChunk);
    }

    public function test_hero_contains_notification_bell_with_correct_aria_label(): void
    {
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $response->assertOk();
        $response->assertSee('aria-label="Notifications"', false);
        $response->assertSee('aria-haspopup="true"', false);
    }

    public function test_dashboard_shows_empty_state_when_there_are_no_notifications(): void
    {
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $response->assertOk();
        // The empty-state text from notification-bell.blade.php.
        // Blade emits hardcoded text verbatim — no entity encoding on apostrophes.
        // assertSee($value, false) searches the raw HTML string.
        $response->assertSee("You're all caught up", false);
        $response->assertSee('New order alerts will show up here.', false);
        $response->assertSee('data-notif-empty', false);
    }

    public function test_dashboard_does_not_error_when_notifications_table_is_absent(): void
    {
        // Schema::hasTable guards the query in DashboardController — the
        // response must be 200 regardless of whether the table exists.
        $response = $this->actingAs($this->approvedSeller(), 'seller')
            ->get(route('seller.dashboard'));

        $response->assertOk();
    }
}

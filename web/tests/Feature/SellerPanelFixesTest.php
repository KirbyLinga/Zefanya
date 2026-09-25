<?php

namespace Tests\Feature;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Verifies the three seller-panel fixes:
 *
 * FIX 1 — Hero layout
 *   - The toolbar (.sp-hero__toolbar) appears AFTER the body (.sp-hero__body)
 *     in the DOM (CSS flex handles the visual right-alignment).
 *
 * FIX 2 — Theme toggle removed from sidebar
 *   - Exactly ONE [data-theme-toggle] exists on the dashboard page.
 *   - That single toggle is inside the hero card ([data-dashboard-hero]),
 *     not inside #sellerSidebar.
 *
 * FIX 3 — Collapsed-sidebar tooltip infrastructure
 *   - Every nav item rendered by sidebar-nav-item has a data-tooltip attribute.
 *   - The logout button has an aria-label of "Log out".
 *   - The sidebar toggle button has a data-tooltip attribute.
 */
class SellerPanelFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function dashboardResponse(): TestResponse
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        return $this->actingAs($seller, 'seller')
            ->get(route('seller.dashboard'));
    }

    // ── FIX 1 ───────────────────────────────────────────────────────────────

    public function test_hero_body_appears_before_toolbar_in_dom(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        $heroStart = mb_strpos($content, 'data-dashboard-hero');
        $bodyPos = mb_strpos($content, 'sp-hero__body', $heroStart);
        $toolbarPos = mb_strpos($content, 'sp-hero__toolbar', $heroStart);

        $this->assertNotFalse($heroStart, 'data-dashboard-hero not found.');
        $this->assertNotFalse($bodyPos, 'sp-hero__body not found inside hero.');
        $this->assertNotFalse($toolbarPos, 'sp-hero__toolbar not found inside hero.');
        $this->assertLessThan(
            $toolbarPos,
            $bodyPos,
            'sp-hero__body must appear before sp-hero__toolbar in the DOM.'
        );
    }

    // ── FIX 2 ───────────────────────────────────────────────────────────────

    public function test_dashboard_has_exactly_one_theme_toggle(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        $count = substr_count($content, 'data-theme-toggle');

        $this->assertSame(
            1,
            $count,
            "Expected exactly 1 [data-theme-toggle] on the page, found {$count}."
        );
    }

    public function test_theme_toggle_is_inside_hero_not_sidebar(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        // Locate the hero and sidebar blocks.
        $heroStart = mb_strpos($content, 'data-dashboard-hero');
        $sidebarStart = mb_strpos($content, 'id="sellerSidebar"');

        $this->assertNotFalse($heroStart, 'data-dashboard-hero not found.');
        $this->assertNotFalse($sidebarStart, 'sellerSidebar not found.');

        $togglePos = mb_strpos($content, 'data-theme-toggle');
        $this->assertNotFalse($togglePos, 'data-theme-toggle not found.');

        // The toggle must be inside the hero section (which starts before the
        // main content area; sidebar comes before hero in the page source, so
        // we verify the toggle is NOT inside the sidebar's rendered block).
        // Strategy: extract the sidebar's HTML up to </aside> and assert the
        // toggle is absent from that chunk.
        $asideEnd = mb_strpos($content, '</aside>', $sidebarStart);
        $sidebarHtml = mb_substr($content, $sidebarStart, $asideEnd - $sidebarStart + 8);

        $this->assertStringNotContainsString(
            'data-theme-toggle',
            $sidebarHtml,
            'data-theme-toggle must not appear inside #sellerSidebar.'
        );

        // And it must appear somewhere after the sidebar closes (i.e. in main content).
        $toggleAfterSidebar = mb_strpos($content, 'data-theme-toggle', $asideEnd);
        $this->assertNotFalse(
            $toggleAfterSidebar,
            'data-theme-toggle must appear in the main content area (after the sidebar).'
        );
    }

    // ── FIX 3 ───────────────────────────────────────────────────────────────

    public function test_sidebar_nav_items_have_data_tooltip(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        // Every nav item is an <a> with aria-label and data-tooltip. Spot-check
        // several known labels from sidebar.blade.php's $sections default.
        $expectedLabels = [
            'Dashboard',
            'Products',
            'Orders',
            'Account',
        ];

        foreach ($expectedLabels as $label) {
            $this->assertStringContainsString(
                'data-tooltip="'.$label.'"',
                $content,
                "Nav item '{$label}' must have data-tooltip=\"{$label}\"."
            );
        }
    }

    public function test_logout_button_has_aria_label(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        $this->assertStringContainsString(
            'aria-label="Log out"',
            $content,
            'The logout button must have aria-label="Log out".'
        );
    }

    public function test_sidebar_toggle_button_has_data_tooltip(): void
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();

        // The hamburger button renders with data-tooltip (value is set
        // initially in sidebar-brand, then updated by JS — the blade value
        // is "Collapse sidebar" on first render).
        $this->assertStringContainsString(
            'data-tooltip="Collapse sidebar"',
            $content,
            'The sidebar toggle button must have a data-tooltip attribute.'
        );
    }
}

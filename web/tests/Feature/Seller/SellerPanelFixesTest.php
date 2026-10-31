<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
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

    private function sidebarHtml(): string
    {
        $content = $this->dashboardResponse()->assertOk()->getContent();
        $start = mb_strpos($content, 'id="sellerSidebar"');

        return mb_substr($content, $start, mb_strpos($content, '</aside>', $start) - $start);
    }

    public function test_grouping_the_sidebar_keeps_every_seller_page_reachable(): void
    {
        $sidebar = $this->sidebarHtml();

        foreach ([
            'seller.dashboard', 'seller.notifications.index', 'seller.products.index', 'seller.store-categories.index',
            'seller.store-sales.index', 'seller.promotions.index', 'seller.vouchers.index', 'seller.orders.index',
            'seller.shipments.index', 'seller.returns.index', 'seller.messages.index', 'seller.feedback.index',
            'seller.earnings.index', 'seller.payout-requests.index', 'seller.reports.index',
            'seller.shipping-providers.index', 'seller.account.index',
        ] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $sidebar, $route);
        }

        $this->assertStringContainsString('Discounts &amp; Vouchers', $sidebar);
        $this->assertStringContainsString('aria-label="Log out"', $sidebar);
    }

    public function test_the_sidebar_starts_with_eight_buttons_and_every_group_closed(): void
    {
        $this->assertSame(1, preg_match('/id="sellerNav".*?<\/nav>/s', $this->sidebarHtml(), $nav));

        $this->assertSame(6, substr_count($nav[0], '<summary'));
        $this->assertSame(2, preg_match_all('/<a\s/', preg_replace('/<details.*?<\/details>/s', '', $nav[0])));
        $this->assertSame(0, preg_match('/<details[^>]*\sopen/', $nav[0]));
    }

    public function test_the_group_holding_the_current_page_opens_including_on_its_inner_pages(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        foreach ([route('seller.shipments.index'), route('seller.earnings.index'), route('seller.vouchers.create')] as $url) {
            $content = $this->actingAs($seller, 'seller')->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match_all('/<details[^>]*\sopen/', $content), $url);
        }
    }

    public function test_a_closed_customers_group_still_shows_unread_messages(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $conversation = Conversation::open($buyer->id, $seller->id);

        $this->assertSame(1, preg_match('/aria-label="Customers".*?<\/summary>/s', $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->getContent(), $summary));
        $this->assertStringNotContainsString('group-open:hidden', $summary[0]);

        Message::query()->create(['conversation_id' => $conversation->id, 'sender_role' => 'buyer', 'sender_id' => $buyer->id, 'body' => 'Hello']);

        $this->assertSame(1, preg_match('/aria-label="Customers".*?<\/summary>/s', $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->getContent(), $summary));
        $this->assertStringContainsString('group-open:hidden', $summary[0]);
    }

    public function test_the_sidebar_list_scrolls_so_log_out_stays_on_screen(): void
    {
        $this->assertSame(1, preg_match('/<nav class="[^"]*min-h-0[^"]*overflow-y-auto[^"]*" id="sellerNav"/', $this->sidebarHtml()));
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

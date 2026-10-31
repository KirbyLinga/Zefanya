<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function signedIn(string $role): Admin
    {
        $admin = Admin::factory()->create(['role' => $role]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /** Hrefs in the sidebar and in any tabs on the page, excluding the logout form. */
    private function navigationLinks(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $links = (new \DOMXPath($document))->query('//aside[@id="adminSidebar"]//a/@href | //nav[@data-admin-tabs]//a/@href');

        return array_values(array_unique(array_map(fn ($link) => $link->nodeValue, iterator_to_array($links))));
    }

    public function test_every_sidebar_and_tab_link_opens_for_a_super_admin(): void
    {
        $this->signedIn('super_admin');
        $queue = [route('admin.dashboard')];
        $visited = [];

        while ($queue !== []) {
            $url = array_shift($queue);
            if (isset($visited[$url])) {
                continue;
            }
            $response = $this->get($url);
            $response->assertOk();
            $visited[$url] = true;
            array_push($queue, ...$this->navigationLinks($response->getContent()));
        }

        foreach ([
            'admin.dashboard', 'admin.users.index', 'admin.hubs.index', 'admin.partnerships.index', 'admin.products.index',
            'admin.vouchers.index', 'admin.incidents.index', 'admin.returns.index', 'admin.settings.index', 'admin.two-factor.show',
        ] as $route) {
            $this->assertArrayHasKey(route($route), $visited, $route);
        }
        foreach (['buyer', 'seller', 'logistics'] as $type) {
            $this->assertArrayHasKey(route('admin.registrations.index', ['type' => $type]), $visited, $type);
        }
    }

    public function test_the_sidebar_has_eleven_items_and_no_more_tools(): void
    {
        $this->signedIn('super_admin');

        $response = $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('More tools');

        $this->assertSame(11, substr_count($response->getContent(), 'data-admin-nav-item'));
        $response->assertSeeInOrder(['Dashboard', 'Accounts', 'Registrations', 'User accounts', 'Marketplace &amp; operations', 'Logistics network', 'Products', 'Vouchers', 'Returns &amp; disputes', 'Parcel issues', 'Money', 'Settlements', 'Finance', 'Settings'], false);
    }

    public function test_unbuilt_items_are_disabled_text_not_links(): void
    {
        $this->signedIn('super_admin');

        $content = $this->get(route('admin.dashboard'))->getContent();

        $this->assertSame(2, substr_count($content, 'aria-disabled="true" title="'));
        foreach (['Returns &amp; disputes', 'Finance'] as $label) {
            $this->assertStringContainsString('<span data-admin-nav-item aria-disabled="true" title="'.$label.': coming soon"', $content);
        }
        $this->assertStringNotContainsString(route('admin.settlements.index'), $content);
        $this->assertStringContainsString(route('admin.logistics-remittances.index'), $content);

        $this->get(route('admin.settings.index'))->assertOk()
            ->assertSeeInOrder(['Platform settings', 'Audit log'])
            ->assertSee(route('admin.audit-logs.index'), false);
    }

    public function test_a_moderator_sees_only_what_it_can_open(): void
    {
        $this->signedIn('moderator');

        $content = $this->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee(route('admin.settings.index'), false)
            ->assertDontSee(route('admin.vouchers.index'), false)
            ->assertDontSee('Settlements')
            ->assertDontSee('Finance')
            ->assertSee(route('admin.products.index'), false)
            ->assertSee(route('admin.incidents.index'), false)
            ->getContent();

        foreach ($this->navigationLinks($content) as $link) {
            $this->get($link)->assertOk();
        }
    }

    public function test_support_only_gets_the_dashboard_and_its_own_security_page(): void
    {
        $this->signedIn('support');

        $links = $this->navigationLinks($this->get(route('admin.dashboard'))->assertOk()->getContent());

        $this->assertEqualsCanonicalizing([route('admin.dashboard'), route('admin.two-factor.show')], $links);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function groupedPages(): array
    {
        return [
            'hubs' => ['admin.hubs.index', 'Logistics network'],
            'partnerships' => ['admin.partnerships.index', 'Logistics network'],
            'incidents' => ['admin.incidents.index', 'Parcel issues'],
            'return exceptions' => ['admin.returns.index', 'Parcel issues'],
            'new voucher' => ['admin.vouchers.create', 'Vouchers'],
        ];
    }

    #[DataProvider('groupedPages')]
    public function test_a_page_highlights_its_parent_sidebar_item(string $route, string $parent): void
    {
        $this->signedIn('super_admin');

        $content = $this->get(route($route))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a data-admin-nav-item[^>]*aria-label="'.$parent.'"[^>]*aria-current="page"/s', $content));
        $this->assertSame(1, preg_match_all('/<a data-admin-nav-item[^>]*aria-current="page"/s', $content));
    }

    public function test_only_the_current_tab_is_marked_active(): void
    {
        $this->signedIn('super_admin');

        $content = $this->get(route('admin.returns.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/href="'.preg_quote(route('admin.returns.index'), '/').'"\s*aria-current="page"/', $content));
        $this->assertSame(0, preg_match('/href="'.preg_quote(route('admin.incidents.index'), '/').'"\s*aria-current="page"/', $content));
    }

    public function test_registration_tabs_use_the_type_filter_and_keep_the_others(): void
    {
        $this->signedIn('admin');

        $response = $this->get(route('admin.registrations.index', ['type' => 'seller', 'status' => 'approved']))->assertOk();

        $sellers = e(route('admin.registrations.index', ['status' => 'approved', 'type' => 'seller']));
        $this->assertSame(1, preg_match('/href="'.preg_quote($sellers, '/').'"\s*aria-current="page"/', $response->getContent()));
        $response->assertSee(e(route('admin.registrations.index', ['status' => 'approved', 'type' => 'buyer'])), false);
    }
}

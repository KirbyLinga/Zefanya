<?php

namespace Tests\Feature\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The logistics sidebar has nine items. Pages that belong to one job share an
 * item and switch with tabs, and the item stays lit on every tab.
 */
class LogisticsSidebarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->provider = LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Approved->value,
            'email_verified_at' => now(),
        ]);
    }

    private function xpath(string $route): \DOMXPath
    {
        $html = $this->actingAs($this->provider, 'logistics')->get(route($route))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    /** @return list<string> */
    private function sidebarLabels(\DOMXPath $xpath): array
    {
        $labels = [];
        foreach ($xpath->query('//*[@id="logisticsSidebar"]//a[@data-tooltip]') as $link) {
            $labels[] = $link->getAttribute('data-tooltip');
        }

        return $labels;
    }

    private function activeItem(\DOMXPath $xpath): ?string
    {
        $active = $xpath->query('//*[@id="logisticsSidebar"]//a[@aria-current="page"]');

        return $active->length === 1 ? $active->item(0)->getAttribute('data-tooltip') : null;
    }

    public function test_the_sidebar_has_the_nine_items_in_order(): void
    {
        $labels = $this->sidebarLabels($this->xpath('logistics.dashboard'));

        $this->assertSame(['Scan parcel', 'Dashboard', 'Incoming & sorting', 'Deliveries', 'Exceptions', 'Transfers', 'Returns', 'Riders', 'Money', 'Setup'], $labels);
    }

    public function test_notifications_stay_reachable_from_the_bell_on_every_page(): void
    {
        $xpath = $this->xpath('logistics.riders.index');

        $this->assertSame(0, $xpath->query('//*[@id="logisticsSidebar"]//a[@href="'.route('logistics.notifications.index').'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="lg-notification-bell"]')->length);
    }

    public function test_scan_parcel_is_outlined_as_the_main_action_with_its_usual_icon(): void
    {
        $scan = $this->xpath('logistics.dashboard')->query('//*[@id="logisticsSidebar"]//a[@data-tooltip="Scan parcel"]')->item(0);

        $this->assertStringContainsString('border-[var(--sidebar-active-border)]', $scan->getAttribute('class'));
        $this->assertStringContainsString('data-lucide="scan-line"', $scan->ownerDocument->saveHTML($scan));
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function groupedPages(): array
    {
        return [
            'incoming' => ['logistics.incoming-parcels.index', 'Incoming & sorting', ['Incoming parcels', 'Parcel sorting']],
            'sorting' => ['logistics.parcel-sorting.index', 'Incoming & sorting', ['Incoming parcels', 'Parcel sorting']],
            'assign' => ['logistics.delivery-assignments.index', 'Deliveries', ['Assign deliveries', 'Delivery tracking']],
            'tracking' => ['logistics.delivery-monitoring.index', 'Deliveries', ['Assign deliveries', 'Delivery tracking']],
            'handoffs' => ['logistics.handoffs.index', 'Transfers', ['Provider handoffs', 'Transfer batches', 'Couriers']],
            'batches' => ['logistics.manifests.index', 'Transfers', ['Provider handoffs', 'Transfer batches', 'Couriers']],
            'earnings' => ['logistics.earnings.index', 'Money', ['Earnings', 'COD remittances', 'Lost parcels']],
            'cod' => ['logistics.cod-remittances.index', 'Money', ['Earnings', 'COD remittances', 'Lost parcels']],
            'hubs' => ['logistics.hubs.index', 'Setup', ['Hubs & stations', 'Transport connections', 'Provider partnerships', 'Delivery areas', 'Activity log']],
            'partnerships' => ['logistics.partnerships.index', 'Setup', ['Hubs & stations', 'Transport connections', 'Provider partnerships', 'Delivery areas', 'Activity log']],
            'activity' => ['logistics.audit-logs.index', 'Setup', ['Hubs & stations', 'Transport connections', 'Provider partnerships', 'Delivery areas', 'Activity log']],
        ];
    }

    /** @param  list<string>  $tabs */
    #[DataProvider('groupedPages')]
    public function test_grouped_pages_show_their_tabs_and_keep_the_sidebar_item_lit(string $route, string $item, array $tabs): void
    {
        $xpath = $this->xpath($route);

        $this->assertSame($item, $this->activeItem($xpath));

        $labels = [];
        foreach ($xpath->query('//nav[@data-logistics-tabs]/a') as $tab) {
            $labels[] = trim($tab->textContent);
        }
        $this->assertSame($tabs, $labels);

        $current = $xpath->query('//nav[@data-logistics-tabs]/a[@aria-current="page"]');
        $this->assertSame(1, $current->length);
        $this->assertSame(route($route), $current->item(0)->getAttribute('href'));
    }

    public function test_pages_outside_a_group_have_no_tabs(): void
    {
        $xpath = $this->xpath('logistics.returns.index');

        $this->assertSame('Returns', $this->activeItem($xpath));
        $this->assertSame(0, $xpath->query('//nav[@data-logistics-tabs]')->length);
    }
}

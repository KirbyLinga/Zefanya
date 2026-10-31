<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Notifications\Seller\LowStockAlert;
use App\Services\Seller\StockService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Audit F-04 / SEL-INV-008: seller-owned database notifications can be read and cleared. */
class SellerNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function alert($owner, string $message = 'Inventory alert', bool $read = false, int $age = 0): mixed
    {
        return $owner->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => LowStockAlert::class,
            'data' => ['type' => 'low_stock', 'title' => 'Low stock', 'message' => $message],
            'read_at' => $read ? now() : null, 'created_at' => now()->subMinutes($age),
        ]);
    }

    public function test_low_stock_alert_can_be_seen_and_marked_read_without_deleting_it(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 6, 'low_stock_threshold' => 5]);
        app(StockService::class)->set($product->variants()->sole(), 5);
        $row = $seller->notifications()->sole();
        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))->assertOk()
            ->assertSee($product->name.' has 5 units left.')->assertSee('1 unread.')
            ->assertSee('Mark as read')->assertSee(route('seller.products.index', ['stock' => 'low']), false);
        $this->assertNull($row->fresh()->read_at);
        $this->postJson(route('seller.notifications.read', $row->id))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($row->fresh()->read_at);
        $this->assertSame(1, $seller->notifications()->count());
        $this->get(route('seller.notifications.index', ['filter' => 'unread']))->assertOk()->assertSee('You have no unread notifications.')->assertDontSee($product->name.' has 5 units left.');
        $this->get(route('seller.notifications.index'))->assertSee($product->name.' has 5 units left.')->assertSee('Read');
    }

    public function test_dashboard_bell_links_to_the_page_and_exposes_scoped_read_controls(): void
    {
        $seller = Seller::factory()->create();
        $row = $this->alert($seller, 'Own low-stock alert');
        $this->alert(Seller::factory()->create(), 'Foreign low-stock alert');
        $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->assertOk()
            ->assertSee('Own low-stock alert')->assertDontSee('Foreign low-stock alert')
            ->assertSee('Notifications, 1 unread')->assertSee('Mark all as read')
            ->assertSee(route('seller.notifications.read', $row->id), false)->assertSee(route('seller.notifications.index'), false);
        $this->post(route('seller.notifications.readAll'))->assertRedirect();
        $this->get(route('seller.dashboard'))->assertOk()->assertSee('Notifications, 0 unread');
    }

    public function test_mark_one_and_all_only_touch_the_signed_in_seller_and_preserve_read_timestamps(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create();
        $first = $this->alert($seller);
        $second = $this->alert($seller);
        $foreign = $this->alert(Seller::factory()->create());
        $this->actingAs($seller, 'seller')->postJson(route('seller.notifications.read', $first->id))->assertOk()->assertJsonPath('unread_count', 1);
        $readAt = $first->fresh()->read_at->toDateTimeString();
        $this->travel(10)->minutes();
        $this->postJson(route('seller.notifications.read', $first->id))->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertSame($readAt, $first->fresh()->read_at->toDateTimeString());
        $this->postJson(route('seller.notifications.readAll'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame($readAt, $first->fresh()->read_at->toDateTimeString());
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertSame(2, $seller->notifications()->count());
    }

    public function test_foreign_seller_foreign_role_and_unknown_ids_are_not_found(): void
    {
        $seller = Seller::factory()->create();
        $foreign = $this->alert(Seller::factory()->create());
        $buyer = $this->alert(Buyer::factory()->create(['id' => $seller->id]));
        $this->actingAs($seller, 'seller');
        foreach ([$foreign->id, $buyer->id, (string) Str::uuid()] as $id) {
            $this->postJson(route('seller.notifications.read', $id))->assertNotFound();
        }
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertNull($buyer->fresh()->read_at);
    }

    public function test_all_and_unread_lists_are_scoped_paginated_and_do_not_mark_alerts_read(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create();
        for ($index = 0; $index < 22; $index++) {
            $this->alert($seller, 'Alert '.$index, age: $index);
        }
        $this->alert($seller, 'Already read', true, 30);
        $this->alert(Seller::factory()->create(), 'Foreign alert');
        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index', ['filter' => 'unread']))->assertOk()
            ->assertDontSee('Foreign alert')->assertDontSee('Already read')
            ->assertViewHas('notifications', fn ($rows) => $rows->total() === 22 && $rows->count() === 20 && $rows->first()->data['message'] === 'Alert 0');
        $this->get(route('seller.notifications.index', ['filter' => 'unread', 'page' => 2]))->assertOk()
            ->assertSee('Alert 20')->assertSee('Alert 21')->assertDontSee('Already read');
        $this->assertSame(22, $seller->unreadNotifications()->count());
    }

    public function test_classic_form_returns_to_unread_filter_and_empty_state_after_read_all(): void
    {
        $seller = Seller::factory()->create();
        $this->alert($seller, 'Soon read');
        $url = route('seller.notifications.index', ['filter' => 'unread']);
        $this->actingAs($seller, 'seller')->from($url)->post(route('seller.notifications.readAll'))
            ->assertRedirect($url)->assertSessionHas('success');
        $this->get($url)->assertOk()->assertSee("You're all caught up", false)->assertDontSee('Soon read');
        $this->assertSame(1, $seller->notifications()->count());
    }

    public function test_titles_and_messages_are_escaped_and_untrusted_notification_urls_are_not_links(): void
    {
        $seller = Seller::factory()->create();
        $row = $this->alert($seller);
        $row->update(['data' => ['title' => '<script>alert(1)</script>', 'message' => '<img src=x onerror=alert(2)>', 'url' => 'https://unsafe.example/steal']]);
        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<img src=x onerror=alert(2)>')->assertDontSee('https://unsafe.example/steal', false);
    }

    private function typed($owner, array $data, bool $read = false, ?Carbon $at = null): mixed
    {
        return $owner->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => LowStockAlert::class, 'data' => $data,
            'read_at' => $read ? now() : null, 'created_at' => $at ?? now(),
        ]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function notificationTypes(): array
    {
        return [
            'new order' => ['seller_new_order', 'New order', 'shopping-bag', 'View order'],
            'cancelled' => ['buyer_order_cancelled', 'Order cancelled', 'circle-x', 'View order'],
            'delivery failed' => ['seller_delivery_failed', 'Delivery failed', 'truck', 'View order'],
            'parcel returned' => ['seller_parcel_returned', 'Parcel returned', 'package-check', 'View order'],
            'return requested' => ['buyer_return_requested', 'Return requested', 'undo-2', 'View returns'],
            'return disputed' => ['return_disputed', 'Return disputed', 'scale', 'View returns'],
            'dispute resolved' => ['return_dispute_resolved', 'Dispute resolved', 'scale', 'View returns'],
        ];
    }

    #[DataProvider('notificationTypes')]
    public function test_each_type_has_its_own_title_icon_and_next_step(string $type, string $title, string $icon, string $action): void
    {
        $seller = Seller::factory()->create();
        $this->typed($seller, ['type' => $type, 'seller_order_id' => 7, 'message' => 'Details here']);

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))->assertOk()
            ->assertSee($title)
            ->assertSee('data-lucide="'.$icon.'"', false)
            ->assertSee($action)
            ->assertSee($action === 'View order' ? route('seller.orders.show', 7) : route('seller.returns.index'), false)
            ->assertDontSee('<h3 class="break-words font-sans text-[15px] font-semibold text-[var(--text-ink)]">Notification</h3>', false);
    }

    public function test_pickup_failed_links_to_its_order_and_keeps_its_stored_title(): void
    {
        $seller = Seller::factory()->create();
        $this->typed($seller, ['type' => 'pickup_failed', 'title' => 'Pickup Failed', 'order_id' => 9, 'message' => 'Attempt failed']);

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))
            ->assertSee('Pickup Failed')->assertSee(route('seller.orders.show', 9), false);
    }

    public function test_an_unknown_type_still_gets_a_readable_title_and_no_invented_link(): void
    {
        $seller = Seller::factory()->create();
        $this->typed($seller, ['type' => 'parcel_incident_reported', 'message' => 'Something happened']);
        $this->typed($seller, ['message' => 'No type at all']);

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))->assertOk()
            ->assertSee('Parcel Incident Reported')
            ->assertSee('>Notification</h3>', false)
            ->assertDontSee('View order');
    }

    public function test_new_orders_show_their_amount_and_the_bell_uses_the_same_title(): void
    {
        $seller = Seller::factory()->create();
        $this->typed($seller, ['type' => 'seller_new_order', 'seller_order_id' => 7, 'amount_minor' => 125000, 'message' => 'New order ORD-1']);

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))->assertSee('₱1,250.00');
        $this->get(route('seller.dashboard'))->assertOk()->assertSee('<span class="sp-notif-item__title">New order</span>', false);
    }

    public function test_notifications_are_grouped_by_philippine_day(): void
    {
        // 00:30 on Oct 31 in Manila is still Oct 30 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-30 16:30:00', 'UTC'));
        $seller = Seller::factory()->create();
        $this->typed($seller, ['message' => 'Sent today'], at: Carbon::parse('2026-10-30 16:10:00', 'UTC'));
        $this->typed($seller, ['message' => 'Sent yesterday'], at: Carbon::parse('2026-10-30 15:50:00', 'UTC'));
        $this->typed($seller, ['message' => 'Sent earlier'], at: Carbon::parse('2026-10-26 04:00:00', 'UTC'));

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))
            ->assertSeeInOrder(['Today', 'Sent today', 'Yesterday', 'Sent yesterday', 'Monday, October 26, 2026', 'Sent earlier']);
    }

    public function test_unread_and_read_notifications_look_different_and_say_so(): void
    {
        $seller = Seller::factory()->create();
        $this->typed($seller, ['message' => 'Fresh one']);

        $this->actingAs($seller, 'seller')->get(route('seller.notifications.index'))
            ->assertSee('data-unread="1"', false)->assertSee('>New</span>', false)->assertSee('Unread (1)');

        $this->post(route('seller.notifications.readAll'));

        $this->get(route('seller.notifications.index'))
            ->assertSee('data-unread="0"', false)->assertDontSee('>New</span>', false)->assertDontSee('Mark as read');
    }

    public function test_guests_and_other_roles_cannot_access_seller_notifications(): void
    {
        $seller = Seller::factory()->create();
        $row = $this->alert($seller);
        foreach ([false, true] as $buyer) {
            if ($buyer) {
                $this->actingAs(Buyer::factory()->create(), 'buyer');
            }
            $this->getJson(route('seller.notifications.index'))->assertUnauthorized();
            $this->postJson(route('seller.notifications.read', $row->id))->assertUnauthorized();
            $this->postJson(route('seller.notifications.readAll'))->assertUnauthorized();
        }
        $this->assertNull($row->fresh()->read_at);
    }

    public function test_pending_sellers_cannot_view_or_clear_notifications(): void
    {
        $seller = Seller::factory()->pending()->create();
        $row = $this->alert($seller);
        $this->actingAs($seller, 'seller')->getJson(route('seller.notifications.index'))->assertForbidden();
        $this->postJson(route('seller.notifications.read', $row->id))->assertForbidden();
        $this->postJson(route('seller.notifications.readAll'))->assertForbidden();
        $this->assertNull($row->fresh()->read_at);
        $this->assertAuthenticatedAs($seller, 'seller');
    }
}

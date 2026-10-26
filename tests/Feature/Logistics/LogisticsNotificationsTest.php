<?php

namespace Tests\Feature\Logistics;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use App\Notifications\Logistics\LogisticsHubDecisionNotification;
use App\Notifications\Logistics\RiderAssignmentsNeedReassignment;
use App\Notifications\Logistics\RiderParcelActivity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class LogisticsNotificationsTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function provider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
    }

    private function notification(object $recipient, array $data = [], bool $read = false, int $minutesAgo = 0): DatabaseNotification
    {
        return $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => RiderParcelActivity::class,
            'data' => $data + ['title' => 'Real parcel update', 'message' => 'Parcel collected from seller'],
            'read_at' => $read ? now() : null,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_dashboard_renders_real_notifications_and_scoped_unread_count(): void
    {
        $provider = $this->provider();
        $own = $this->notification($provider, ['title' => 'A real hub alert']);
        $this->notification($provider, ['title' => 'Earlier alert'], true);
        $this->notification($this->provider(), ['title' => 'Foreign alert']);
        $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard'))->assertOk()
            ->assertSee('A real hub alert')->assertSee('Earlier alert')->assertDontSee('Foreign alert')
            ->assertSee('Notifications, 1 unread')->assertSee(route('logistics.notifications.read', $own->id), false)
            ->assertSee('Mark all as read');
    }

    public function test_recent_returns_latest_eight_but_counts_all_unread_notifications_without_marking_read(): void
    {
        $this->freezeTime();
        $provider = $this->provider();
        for ($index = 0; $index < 11; $index++) {
            $this->notification($provider, ['title' => 'Alert '.$index], false, $index);
        }
        $this->notification($this->provider(), ['title' => 'Foreign alert']);
        $this->actingAs($provider, 'logistics')->getJson(route('logistics.notifications.recent'))->assertOk()
            ->assertJsonPath('unread_count', 11)->assertJsonCount(8, 'notifications')
            ->assertJsonPath('notifications.0.title', 'Alert 0')->assertJsonPath('notifications.7.title', 'Alert 7')
            ->assertJsonPath('notifications.0.read', false);
        $this->assertSame(11, $provider->unreadNotifications()->count());
    }

    public function test_individual_and_all_read_actions_only_update_the_signed_in_provider(): void
    {
        $provider = $this->provider();
        $first = $this->notification($provider);
        $second = $this->notification($provider);
        $foreign = $this->notification($this->provider());
        $this->actingAs($provider, 'logistics')->postJson(route('logistics.notifications.read', $first->id))
            ->assertOk()->assertJsonPath('unread_count', 1);
        $readAt = $first->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->postJson(route('logistics.notifications.read', $first->id))->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertTrue($readAt->equalTo($first->fresh()->read_at));
        $this->postJson(route('logistics.notifications.readAll'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_foreign_role_and_unknown_notifications_cannot_be_marked_read(): void
    {
        $provider = $this->provider();
        $foreign = $this->notification($this->provider());
        $buyerRow = $this->notification(Buyer::factory()->create(['id' => $provider->id]));
        $this->actingAs($provider, 'logistics');
        foreach ([$foreign->id, $buyerRow->id, (string) Str::uuid()] as $id) {
            $this->postJson(route('logistics.notifications.read', $id))->assertNotFound();
        }
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertNull($buyerRow->fresh()->read_at);
    }

    public function test_guests_and_other_roles_cannot_access_notification_endpoints(): void
    {
        $provider = $this->provider();
        $row = $this->notification($provider);
        foreach ([false, true] as $asBuyer) {
            if ($asBuyer) {
                $this->actingAs(Buyer::factory()->create(), 'buyer');
            }
            $this->getJson(route('logistics.notifications.recent'))->assertUnauthorized();
            $this->postJson(route('logistics.notifications.read', $row->id))->assertUnauthorized();
            $this->postJson(route('logistics.notifications.readAll'))->assertUnauthorized();
        }
        $this->assertNull($row->fresh()->read_at);
    }

    public function test_html_read_action_supports_no_javascript_and_notification_content_is_escaped(): void
    {
        $provider = $this->provider();
        $row = $this->notification($provider, ['title' => '<script>bad()</script>', 'message' => '<img src=x onerror=bad()>']);
        $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)
            ->assertSee('&lt;img src=x onerror=bad()&gt;', false);
        $this->from(route('logistics.dashboard'))->post(route('logistics.notifications.read', $row->id))
            ->assertRedirect(route('logistics.dashboard'));
        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_notification_can_be_opened_from_the_bell_and_redirects_to_its_target(): void
    {
        $provider = $this->provider();
        $target = route('logistics.incoming-parcels.index', ['highlight' => 123]);
        $row = $this->notification($provider, ['url' => $target]);
        $openUrl = route('logistics.notifications.open', $row->id);

        $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard'))
            ->assertOk()->assertSee('action="'.$openUrl.'"', false);
        $this->getJson(route('logistics.notifications.recent'))
            ->assertOk()->assertJsonPath('notifications.0.open_url', $openUrl);
        $this->post($openUrl)->assertRedirect($target);

        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_empty_bell_is_truthful(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard'))->assertOk()
            ->assertSee("You're all caught up", false)->assertDontSee('Notifications, 1 unread');
        $this->getJson(route('logistics.notifications.recent'))->assertOk()
            ->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
    }

    public function test_existing_operational_alerts_store_database_notifications(): void
    {
        $provider = $this->provider();
        $courier = $this->assignableCourier($provider);
        $parcel = SellerOrder::factory()->create();
        $provider->notify(new RiderParcelActivity($parcel, RiderParcelActivity::PICKUP));
        $provider->notify(new RiderAssignmentsNeedReassignment($courier->rider, 2));
        $provider->notify(new LogisticsHubDecisionNotification($courier->rider->station, 'approved'));
        $this->assertSame(3, $provider->unreadNotifications()->count());
        $this->assertEqualsCanonicalizing(['hub_decision', 'rider_reassignment_needed', 'rider_parcel_activity'],
            $provider->notifications()->get()->map(fn ($row) => $row->data['type'])->all());
        $this->actingAs($provider, 'logistics')->getJson(route('logistics.notifications.recent'))->assertOk()
            ->assertJsonPath('unread_count', 3)->assertJsonCount(3, 'notifications');
    }

    public function test_unapproved_provider_cannot_read_or_update_notifications(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'pending']);
        $row = $this->notification($provider);
        $this->actingAs($provider, 'logistics')->getJson(route('logistics.notifications.recent'))
            ->assertRedirect(route('home', ['login' => 1]));
        $this->actingAs($provider, 'logistics')->postJson(route('logistics.notifications.read', $row->id))
            ->assertRedirect(route('home', ['login' => 1]));
        $this->actingAs($provider, 'logistics')->postJson(route('logistics.notifications.readAll'))
            ->assertRedirect(route('home', ['login' => 1]));
        $this->assertNull($row->fresh()->read_at);
    }
}

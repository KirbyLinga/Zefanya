<?php

namespace Tests\Feature\Rider;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;
use App\Notifications\Rider\RiderApplicationDecisionNotification;
use App\Policies\RiderApplicationPolicy;
use App\Services\RiderApplicationDecisionService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiderApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_provider_approves_verified_application_with_audit_email_and_working_dashboard(): void
    {
        Notification::fake([RiderApplicationDecisionNotification::class]);
        $app = RiderApplication::factory()->pending()->create();
        $hash = $app->password;
        $this->actingAs($app->provider, 'logistics')->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $app->refresh();
        $this->assertTrue($app->isApproved());
        $this->assertSame($hash, $app->password);
        $this->assertDatabaseHas('rider_application_decisions', ['rider_application_id' => $app->id, 'logistics_provider_id' => $app->logistics_provider_id, 'from_status' => 'pending', 'to_status' => 'approved', 'reason' => null]);
        Notification::assertSentTo($app, RiderApplicationDecisionNotification::class, function ($notice, $channels) use ($app) {
            $this->assertSame(['mail'], $channels);
            $this->assertSame('approved', $notice->decision);
            $this->assertStringContainsString('approved', $notice->toMail($app)->subject);

            return true;
        });
        $this->post(route('unified.logout'));
        $this->post(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertRedirect(route('rider.dashboard'));
        $this->get(route('rider.dashboard'))->assertOk()->assertSee($app->provider->business_name)->assertSee('Approved');
        $this->assertGuest('logistics');
        $this->assertDatabaseCount('couriers', 0);
        $this->assertFalse(Schema::hasTable('rider_activation_tokens'));
    }

    public function test_rejection_requires_reason_sends_email_and_denies_login(): void
    {
        Notification::fake([RiderApplicationDecisionNotification::class]);
        $app = RiderApplication::factory()->pending()->create();
        $this->actingAs($app->provider, 'logistics')->postJson(route('logistics.riders.decide', $app), ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('logistics.riders.decide', $app), ['decision' => 'rejected', 'reason' => 'Unreadable OR/CR'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('rider_application_decisions', ['rider_application_id' => $app->id, 'to_status' => 'rejected', 'reason' => 'Unreadable OR/CR']);
        Notification::assertSentTo($app, RiderApplicationDecisionNotification::class, fn ($n) => $n->decision === 'rejected' && $n->reason === 'Unreadable OR/CR');
        $this->post(route('unified.logout'));
        $this->postJson(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertUnprocessable();
        $this->assertGuest('rider');
    }

    public function test_other_provider_cannot_list_view_download_or_decide_application(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $other = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->actingAs($other, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertDontSee($app->email);
        $this->get(route('logistics.riders.show', $app))->assertNotFound();
        $this->get(route('logistics.rider-applications.documents.show', [$app, 'id']))->assertNotFound();
        $this->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $app->fresh()->status);
        $this->assertDatabaseCount('rider_application_decisions', 0);
    }

    public function test_unverified_application_is_hidden_and_cannot_be_reviewed(): void
    {
        $app = RiderApplication::factory()->create();
        $this->actingAs($app->provider, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertDontSee($app->email);
        $this->get(route('logistics.riders.show', $app))->assertNotFound();
        $this->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertForbidden();
        $this->assertDatabaseCount('rider_application_decisions', 0);
    }

    public static function terminalStatuses(): array
    {
        return [['approved'], ['rejected'], ['suspended']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_decisions_cannot_be_replayed_or_overwrite_final_state(string $status): void
    {
        Notification::fake([RiderApplicationDecisionNotification::class]);
        $app = RiderApplication::factory()->pending()->create(['status' => $status]);
        $this->actingAs($app->provider, 'logistics')->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertStatus(409);
        $this->assertSame($status, $app->fresh()->status);
        $this->assertDatabaseCount('rider_application_decisions', 0);
        Notification::assertNothingSent();
    }

    public function test_duplicate_approval_writes_and_emails_exactly_once(): void
    {
        Notification::fake([RiderApplicationDecisionNotification::class]);
        $app = RiderApplication::factory()->pending()->create();
        $this->actingAs($app->provider, 'logistics')->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertOk();
        $this->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertStatus(409);
        $this->assertDatabaseCount('rider_application_decisions', 1);
        Notification::assertSentToTimes($app, RiderApplicationDecisionNotification::class, 1);
    }

    public function test_delivery_failure_rolls_back_decision_and_audit(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->actingAs($app->provider, 'logistics')->postJson(route('logistics.riders.decide', $app), ['decision' => 'approved'])->assertStatus(503);
        $this->assertSame('pending', $app->fresh()->status);
        $this->assertDatabaseCount('rider_application_decisions', 0);
    }

    public function test_guests_buyers_and_admins_cannot_decide(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $url = route('logistics.riders.decide', $app);
        $this->postJson($url, ['decision' => 'approved'])->assertUnauthorized();
        $this->actingAs(Buyer::factory()->create(), 'buyer')->postJson($url, ['decision' => 'approved'])->assertUnauthorized();
        $this->actingAs(Admin::create(['name' => 'Test Admin', 'email' => 'admin@example.com', 'password' => 'Password1!']), 'admin')->postJson($url, ['decision' => 'approved'])->assertUnauthorized();
        $this->assertDatabaseCount('rider_application_decisions', 0);
    }

    public function test_service_rechecks_provider_status_and_application_owner(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $other = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->expectException(HttpException::class);
        app(RiderApplicationDecisionService::class)->decide($other, $app, 'approved', null);
    }

    public function test_policy_checks_provider_status_ownership_email_and_pending_state(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $policy = new RiderApplicationPolicy;
        $this->assertTrue($policy->view($app->provider, $app));
        $this->assertTrue($policy->decide($app->provider, $app));
        $other = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->assertFalse($policy->view($other, $app));
        $provider = $app->provider;
        $provider->forceFill(['status' => 'rejected'])->save();
        $this->assertFalse($policy->decide($provider, $app));
        $provider->forceFill(['status' => 'approved'])->save();
        $app->status = 'approved';
        $this->assertTrue($policy->view($provider, $app));
        $this->assertFalse($policy->decide($provider, $app));
        $app->email_verified_at = null;
        $this->assertFalse($policy->view($provider, $app));
    }

    public function test_documents_and_decision_reasons_are_escaped_in_provider_detail(): void
    {
        Storage::fake('local');
        Notification::fake([RiderApplicationDecisionNotification::class]);
        $app = RiderApplication::factory()->pending()->create();
        Storage::disk('local')->put($app->upload_id_path, '%PDF-1.4 test');
        $this->actingAs($app->provider, 'logistics')->get(route('logistics.rider-applications.documents.show', [$app, 'id']))->assertOk()->assertDownload();
        $this->postJson(route('logistics.riders.decide', $app), ['decision' => 'rejected', 'reason' => '<script>alert(1)</script>'])->assertOk();
        $this->get(route('logistics.riders.show', $app))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        Notification::assertSentTo($app, RiderApplicationDecisionNotification::class);
    }

    public function test_rider_login_clears_other_role_and_logout_clears_every_guard(): void
    {
        $app = RiderApplication::factory()->approved()->create();
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $this->actingAs($buyer, 'buyer')->post(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertRedirect(route('rider.dashboard'));
        $this->assertGuest('buyer');
        $this->assertAuthenticatedAs($app, 'rider');
        $this->post(route('unified.logout'));
        foreach (['rider', 'buyer', 'seller', 'logistics', 'admin'] as $guard) {
            $this->assertGuest($guard);
        }
    }

    public function test_legacy_email_collision_with_rider_is_denied_by_password_login(): void
    {
        $app = RiderApplication::factory()->approved()->create();
        Buyer::factory()->create(['status' => 'approved', 'email' => $app->email]);
        $this->postJson(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertUnprocessable();
        $this->assertGuest('rider');
        $this->assertGuest('buyer');
    }

    public function test_approved_rider_loses_dashboard_access_when_suspended_or_unverified(): void
    {
        $app = RiderApplication::factory()->approved()->create();
        $this->actingAs($app, 'rider')->get(route('rider.dashboard'))->assertOk();
        $app->forceFill(['status' => 'suspended'])->save();
        $this->getJson(route('rider.dashboard'))->assertForbidden();
        $this->assertGuest('rider');
        $app->forceFill(['status' => 'approved', 'email_verified_at' => null])->save();
        $this->actingAs($app, 'rider')->getJson(route('rider.dashboard'))->assertForbidden();
    }

    public function test_service_rejects_stale_provider_approval_and_invalid_decisions(): void
    {
        $app = RiderApplication::factory()->pending()->create();
        $actor = $app->provider;
        LogisticsProvider::whereKey($actor->id)->update(['status' => 'rejected']);
        $this->expectException(HttpException::class);
        app(RiderApplicationDecisionService::class)->decide($actor, $app, 'approved', null);
    }
}

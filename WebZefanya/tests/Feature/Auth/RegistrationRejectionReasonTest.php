<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationRejectionReasonTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function roles(): array
    {
        return [['seller'], ['logistics']];
    }

    private function account(string $role, string $status = 'rejected', ?string $reason = null): Model
    {
        $class = $role === 'seller' ? Seller::class : LogisticsProvider::class;

        return $class::factory()->create(['status' => $status, 'rejection_reason' => $reason, 'email_verified_at' => now(), 'password' => 'Rejected@2026']);
    }

    public static function reasons(): array
    {
        return [['seller', 'Business permit is unreadable.'], ['logistics', 'DTI permit is unreadable.'], ['seller', '0'], ['logistics', '0']];
    }

    #[DataProvider('reasons')]
    public function test_admin_rejection_carries_the_same_reason_in_email_and_at_login(string $role, string $reason): void
    {
        Notification::fake();
        $account = $this->account($role, 'pending');
        $noticeClass = $role === 'seller' ? SellerRegistrationDecision::class : LogisticsRegistrationDecision::class;
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.'.$role.'.reject', $account), ['rejection_reason' => $reason])->assertSessionHas('success');
        $this->assertSame($reason, $account->fresh()->rejection_reason);
        Notification::assertSentTo($account, $noticeClass, function ($notice, $channels) use ($account, $reason): bool {
            $this->assertSame('rejected', $notice->decision);
            $this->assertSame($reason, $notice->reason);
            $this->assertContains('Reason: '.$reason, $notice->toMail($account)->introLines);
            $this->assertStringContainsString('Reason: '.$reason, (string) $notice->toMail($account)->render());

            return $channels === ['mail'];
        });
        Auth::guard('admin')->logout();
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Rejected@2026'])
            ->assertSessionHas('auth.status_message', fn ($message) => str_contains($message, 'Reason: '.$reason));
        $this->assertGuest($role);
        $this->get(route('home', ['login' => 1]))->assertOk()->assertSeeText('Reason: '.$reason);
    }

    #[DataProvider('roles')]
    public function test_wrong_password_does_not_disclose_the_reason(string $role): void
    {
        $account = $this->account($role, reason: 'Private review reason');
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Wrong@2026'])
            ->assertSessionHasErrors('email')->assertSessionMissing('auth.status_message');
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_legacy_rejections_without_a_reason_show_the_generic_message(string $role): void
    {
        $account = $this->account($role);
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Rejected@2026'])
            ->assertSessionHas('auth.status_message', fn ($message) => str_contains($message, 'not approved') && ! str_contains($message, 'Reason:'));
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_reason_is_escaped_on_the_login_surface(string $role): void
    {
        $reason = '<script>alert("review")</script>';
        $account = $this->account($role, reason: $reason);
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Rejected@2026']);
        $this->get(route('home', ['login' => 1]))->assertOk()->assertSee($reason)->assertDontSee($reason, false);
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_existing_rejected_sessions_show_the_reason_when_operations_are_denied(string $role): void
    {
        $account = $this->account($role, reason: 'Correct the business permit.');
        $this->actingAs($account, $role)->get(route($role.'.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]))
            ->assertSessionHas('auth.status_message', fn ($message) => str_contains($message, 'Reason: Correct the business permit.'));
        $this->assertGuest($role);
    }

    public function test_legacy_seller_login_shows_the_reason(): void
    {
        $account = $this->account('seller', reason: 'Correct the business permit.');
        $this->post(route('seller.login.post'), ['email' => $account->email, 'password' => 'Rejected@2026'])
            ->assertSessionHas('auth.status_message', fn ($message) => str_contains($message, 'Reason: Correct the business permit.'));
        $this->assertGuest('seller');
    }
}

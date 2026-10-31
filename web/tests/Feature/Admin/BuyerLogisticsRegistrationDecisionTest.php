<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use App\Services\Admin\BuyerRegistrationDecisionService;
use App\Services\Admin\LogisticsRegistrationDecisionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerLogisticsRegistrationDecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function account(string $role): mixed
    {
        return $role === 'buyer' ? Buyer::factory()->create(['status' => 'pending'])
            : LogisticsProvider::factory()->pending()->create(['province_code' => '030540000', 'province_name' => 'Pampanga']);
    }

    private function notification(string $role): string
    {
        return $role === 'buyer' ? BuyerRegistrationDecision::class : LogisticsRegistrationDecision::class;
    }

    private function service(string $role): mixed
    {
        return app($role === 'buyer' ? BuyerRegistrationDecisionService::class : LogisticsRegistrationDecisionService::class);
    }

    public static function decisions(): array
    {
        return [['buyer', 'approve'], ['buyer', 'reject'], ['logistics', 'approve'], ['logistics', 'reject']];
    }

    public static function decisionPairs(): array
    {
        $cases = [];
        foreach (['buyer', 'logistics'] as $role) {
            foreach (['approved', 'rejected'] as $first) {
                foreach (['approved', 'rejected'] as $second) {
                    $cases[] = [$role, $first, $second];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('decisionPairs')]
    public function test_stale_models_cannot_overwrite_the_first_decision(string $role, string $first, string $second): void
    {
        Notification::fake();
        $account = $this->account($role);
        $stale = $account->fresh();
        $admin = Admin::factory()->create();
        $other = Admin::factory()->create();
        $service = $this->service($role);
        $this->assertTrue($service->decide($account, $admin, $first, 'Incomplete documents'));
        $snapshot = $account->fresh()->getRawOriginal();
        $this->assertFalse($service->decide($stale, $other, $second, 'Different reason'));
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 1);
        $this->assertDatabaseHas('account_action_histories', ['admin_id' => $admin->id, 'account_type' => $role, 'account_id' => $account->id, 'action' => $first]);
        Notification::assertSentToTimes($account, $this->notification($role), 1);
    }

    #[DataProvider('decisions')]
    public function test_http_decisions_record_metadata_history_and_one_email(string $role, string $action): void
    {
        Notification::fake();
        $this->freezeTime();
        $account = $this->account($role);
        $admin = Admin::factory()->create();
        $other = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents', 'approved_by' => $other->id])->assertSessionHas('success');
        $current = $account->fresh();
        $status = $current->status instanceof \BackedEnum ? $current->status->value : $current->status;
        $this->assertSame($action === 'approve' ? 'approved' : 'rejected', $status);
        $this->assertSame($admin->id, $current->approved_by);
        $this->assertSame(now()->toDateTimeString(), $current->approved_at->toDateTimeString());
        $this->assertSame($action === 'reject' ? 'Incomplete documents' : null, $current->rejection_reason);
        $this->assertDatabaseHas('account_action_histories', [
            'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
            'account_type' => $role, 'account_id' => $account->id, 'account_name' => $account->fullName(), 'account_email' => $account->email,
            'action' => $status, 'from_status' => 'pending', 'to_status' => $status,
            'reason' => $action === 'reject' ? 'Incomplete documents' : ucfirst($role).' registration approved.',
        ]);
        if ($role === 'logistics') {
            $this->assertSame($action === 'approve' ? $admin->id : null, $current->province_verified_by);
            $this->assertSame($action === 'approve', $current->province_verified_at !== null);
        }
        $this->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Repeated'])->assertSessionHas('error');
        $this->assertDatabaseCount('account_action_histories', 1);
        Notification::assertSentToTimes($account, $this->notification($role), 1);
        Notification::assertSentTo($account, $this->notification($role), fn ($notice, $channels) => $notice->decision === $status && $channels === ['mail']);
    }

    #[DataProvider('decisions')]
    public function test_notifications_wait_for_the_outer_commit(string $role, string $action): void
    {
        Notification::fake();
        $account = $this->account($role);
        $this->actingAs(Admin::factory()->create(), 'admin');
        DB::beginTransaction();
        $this->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertSessionHas('success');
        Notification::assertNothingSent();
        DB::commit();
        Notification::assertSentToTimes($account, $this->notification($role), 1);
    }

    #[DataProvider('decisions')]
    public function test_guests_cannot_decide_registrations(string $role, string $action): void
    {
        Notification::fake();
        $account = $this->account($role);
        $snapshot = $account->fresh()->getRawOriginal();
        $this->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertRedirect(route('admin.login'));
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('decisions')]
    public function test_support_staff_cannot_decide_registrations(string $role, string $action): void
    {
        Notification::fake();
        $account = $this->account($role);
        $snapshot = $account->fresh()->getRawOriginal();
        $this->actingAs(Admin::factory()->create(['role' => 'support']), 'admin')
            ->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertForbidden();
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('decisions')]
    public function test_outer_rollback_discards_all_decision_side_effects(string $role, string $action): void
    {
        Notification::fake();
        $account = $this->account($role);
        $snapshot = $account->fresh()->getRawOriginal();
        $this->actingAs(Admin::factory()->create(), 'admin');
        DB::beginTransaction();
        $this->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertSessionHas('success');
        DB::rollBack();
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('decisions')]
    public function test_audit_failure_rolls_back_the_status_and_province_verification(string $role, string $action): void
    {
        Notification::fake();
        Exceptions::fake();
        $account = $this->account($role);
        $snapshot = $account->fresh()->getRawOriginal();
        AccountActionHistory::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertServerError();
        } finally {
            AccountActionHistory::flushEventListeners();
        }
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_logistics_without_a_province_cannot_be_approved(): void
    {
        Notification::fake();
        $provider = LogisticsProvider::factory()->pending()->create(['province_code' => null]);
        $snapshot = $provider->fresh()->getRawOriginal();
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.logistics.approve', $provider))->assertSessionHasErrors('province_code');
        $this->assertSame($snapshot, $provider->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    public static function roles(): array
    {
        return [['buyer'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_rejection_requires_a_valid_reason(string $role): void
    {
        Notification::fake();
        $account = $this->account($role);
        $this->actingAs(Admin::factory()->create(), 'admin');
        foreach ([null, '', '  ', ['invalid'], str_repeat('x', 501)] as $reason) {
            $this->post(route('admin.registrations.'.$role.'.reject', $account), ['rejection_reason' => $reason])->assertSessionHasErrors('rejection_reason');
        }
        $this->assertSame('pending', $account->fresh()->status instanceof \BackedEnum ? $account->fresh()->status->value : $account->fresh()->status);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    public static function nonPending(): array
    {
        $cases = [];
        foreach (['buyer', 'logistics'] as $role) {
            foreach (['approved', 'rejected', 'unverified', 'suspended'] as $status) {
                foreach (['approve', 'reject'] as $action) {
                    $cases[] = [$role, $status, $action];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('nonPending')]
    public function test_non_pending_accounts_cannot_be_decided(string $role, string $status, string $action): void
    {
        Notification::fake();
        $account = $this->account($role);
        $account->forceFill(['status' => $status])->save();
        $snapshot = $account->fresh()->getRawOriginal();
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.'.$role.'.'.$action, $account), ['rejection_reason' => 'Incomplete documents'])->assertSessionHas('error');
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }
}

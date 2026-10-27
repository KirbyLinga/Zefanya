<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountActionHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function actions(): array
    {
        $cases = [];
        foreach (['buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class] as $type => $model) {
            $cases[$type.' suspension'] = [$type, $model, 'suspend', 'approved', 'suspended'];
            $cases[$type.' reactivation'] = [$type, $model, 'reactivate', 'suspended', 'approved'];
        }

        return $cases;
    }

    #[DataProvider('actions')]
    public function test_successful_action_records_authenticated_admin_account_reason_and_time(string $type, string $model, string $action, string $from, string $to): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $this->freezeTime();
        $admin = Admin::factory()->create();
        $otherAdmin = Admin::factory()->create();
        $account = $model::factory()->create(['status' => $from]);
        $reason = 'Account reviewed; identity documents verified.';

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.'.$type.'.'.$action, $account), [
                'reason' => $reason,
                'admin_id' => $otherAdmin->id,
                'account_id' => 999999,
                'action' => 'forged',
                'created_at' => '2000-01-01 00:00:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas($account->getTable(), ['id' => $account->id, 'status' => $to]);
        $this->assertDatabaseCount('account_action_histories', 1);
        $this->assertDatabaseHas('account_action_histories', [
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'account_type' => $type,
            'account_id' => $account->id,
            'account_name' => $account->fullName(),
            'account_email' => $account->email,
            'action' => $action === 'suspend' ? 'suspended' : 'reactivated',
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'created_at' => now()->toDateTimeString(),
        ]);
        Notification::assertSentTo($account, AccountStatusChanged::class, fn ($notification) => $notification->status === $to);

        // A repeated submission is rejected without a second audit entry.
        $this->post(route('admin.users.'.$type.'.'.$action, $account), ['reason' => $reason])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseCount('account_action_histories', 1);
    }

    #[DataProvider('actions')]
    public function test_invalid_reasons_do_not_change_accounts_or_write_history(string $type, string $model, string $action, string $from, string $to): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $account = $model::factory()->create(['status' => $from, 'remember_token' => 'keep-token']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach ([null, '', '   ', ['reason'], str_repeat('a', 1001)] as $reason) {
            $this->post(route('admin.users.'.$type.'.'.$action, $account), ['reason' => $reason])
                ->assertRedirect()
                ->assertSessionHasErrors('reason');

            $this->assertDatabaseHas($account->getTable(), ['id' => $account->id, 'status' => $from, 'remember_token' => 'keep-token']);
            $this->assertDatabaseCount('account_action_histories', 0);
        }

        Notification::assertNothingSent();
    }

    #[DataProvider('actions')]
    public function test_pending_accounts_cannot_be_suspended_or_reactivated(string $type, string $model, string $action, string $from, string $to): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $account = $model::factory()->create(['status' => 'pending', 'remember_token' => 'keep-token']);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.users.'.$type.'.'.$action, $account), ['reason' => 'Reviewed by Admin.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas($account->getTable(), ['id' => $account->id, 'status' => 'pending', 'remember_token' => 'keep-token']);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_unauthorized_admin_cannot_change_status_or_write_history(): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $account = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs(Admin::factory()->create(['role' => 'support']), 'admin')
            ->post(route('admin.users.seller.suspend', $account), ['reason' => 'Unauthorized attempt.'])
            ->assertForbidden();

        $this->assertDatabaseHas('sellers', ['id' => $account->id, 'status' => 'approved']);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_status_and_preserves_remember_token(): void
    {
        Notification::fake([AccountStatusChanged::class]);
        Exceptions::fake();
        $account = Seller::factory()->create(['status' => 'approved', 'remember_token' => 'keep-token']);
        $admin = Admin::factory()->create();
        AccountActionHistory::creating(function (): void {
            throw new \RuntimeException('Audit write unavailable.');
        });

        try {
            $this->actingAs($admin, 'admin')
                ->post(route('admin.users.seller.suspend', $account), ['reason' => 'Reviewed by Admin.'])
                ->assertServerError();
        } finally {
            AccountActionHistory::flushEventListeners();
        }

        $this->assertDatabaseHas('sellers', ['id' => $account->id, 'status' => 'approved', 'remember_token' => 'keep-token']);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
        Exceptions::assertReported(\RuntimeException::class);
    }
}

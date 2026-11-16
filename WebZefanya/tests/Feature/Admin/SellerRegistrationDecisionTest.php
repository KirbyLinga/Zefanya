<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\RegistrationController;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerRegistrationDecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function decisions(): array
    {
        return [['approve'], ['reject']];
    }

    public static function decisionPairs(): array
    {
        return [['approve', 'approve'], ['approve', 'reject'], ['reject', 'approve'], ['reject', 'reject']];
    }

    private function decide(RegistrationController $controller, Seller $seller, string $action): mixed
    {
        return $action === 'approve' ? $controller->approveSeller($seller)
            : $controller->rejectSeller($seller, Request::create('/admin/registrations/seller/'.$seller->id.'/reject', 'POST', ['rejection_reason' => 'Incomplete permit']));
    }

    #[DataProvider('decisionPairs')]
    public function test_stale_pending_models_cannot_overwrite_the_first_decision(string $first, string $second): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $this->travelTo(now()->startOfSecond());
        $seller = Seller::factory()->pending()->create();
        $stale = $seller->fresh();
        $firstAdmin = Admin::factory()->create();
        $secondAdmin = Admin::factory()->create();
        $controller = app(RegistrationController::class);
        $this->actingAs($firstAdmin, 'admin');
        $this->assertNotNull($this->decide($controller, $seller, $first)->getSession()->get('success'));
        $original = $seller->fresh()->getRawOriginal();

        $this->travel(10)->minutes();
        $this->actingAs($secondAdmin, 'admin');
        $response = $this->decide($controller, $stale, $second);
        $this->assertSame($original, $seller->fresh()->getRawOriginal());
        $this->assertNotNull($response->getSession()->get('error'));
        $this->assertSame($firstAdmin->id, $seller->fresh()->approved_by);
        $this->assertDatabaseCount('account_action_histories', 1);
        Notification::assertSentToTimes($seller, SellerRegistrationDecision::class, 1);
        Notification::assertSentTo($seller, SellerRegistrationDecision::class, fn ($notification) => $notification->decision === ($first === 'approve' ? 'approved' : 'rejected'));
    }

    #[DataProvider('decisionPairs')]
    public function test_repeated_http_decisions_preserve_the_first_metadata_and_history(string $first, string $second): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $this->travelTo(now()->startOfSecond());
        $seller = Seller::factory()->pending()->create();
        $admin = Admin::factory()->create();
        $other = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->post(route('admin.registrations.seller.'.$first, $seller), ['rejection_reason' => 'Incomplete permit', 'approved_by' => $other->id])->assertSessionHas('success');
        $original = $seller->fresh()->getRawOriginal();
        $this->actingAs($other, 'admin')->post(route('admin.registrations.seller.'.$second, $seller), ['rejection_reason' => 'Different reason'])->assertSessionHas('error');

        $this->assertSame($original, $seller->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 1);
        $this->assertDatabaseHas('account_action_histories', [
            'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
            'account_type' => 'seller', 'account_id' => $seller->id,
            'account_name' => $seller->fullName(), 'account_email' => $seller->email,
            'action' => $first === 'approve' ? 'approved' : 'rejected', 'from_status' => 'pending',
            'to_status' => $first === 'approve' ? 'approved' : 'rejected',
            'reason' => $first === 'approve' ? 'Seller registration approved.' : 'Incomplete permit',
            'created_at' => now()->toDateTimeString(),
        ]);
        $this->assertSame($admin->id, $seller->fresh()->approved_by);
        $this->assertNotNull($seller->fresh()->approved_at);
        $this->assertSame($first === 'reject' ? 'Incomplete permit' : null, $seller->fresh()->rejection_reason);
        Notification::assertSentToTimes($seller, SellerRegistrationDecision::class, 1);
    }

    public static function nonPendingActions(): array
    {
        $cases = [];
        foreach (['approved', 'rejected', 'suspended', 'unverified'] as $status) {
            foreach (['approve', 'reject'] as $action) {
                $cases[] = [$status, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('nonPendingActions')]
    public function test_non_pending_sellers_cannot_be_reviewed(string $status, string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->create(['status' => $status]);
        $original = $seller->fresh()->getRawOriginal();
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Incomplete permit'])->assertSessionHas('error');
        $this->assertSame($original, $seller->fresh()->getRawOriginal());
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    public static function authorizedRoles(): array
    {
        $cases = [];
        foreach (['super_admin', 'admin', 'moderator'] as $role) {
            foreach (['approve', 'reject'] as $action) {
                $cases[] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('authorizedRoles')]
    public function test_authorized_reviewers_record_one_decision_and_notification(string $role, string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->pending()->create();
        $admin = Admin::factory()->create(['role' => $role]);
        $this->actingAs($admin, 'admin')->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Incomplete permit'])->assertSessionHas('success');
        $this->assertSame($action === 'approve' ? 'approved' : 'rejected', $seller->fresh()->status);
        $this->assertSame($admin->id, $seller->fresh()->approved_by);
        $this->assertDatabaseCount('account_action_histories', 1);
        Notification::assertSentToTimes($seller, SellerRegistrationDecision::class, 1);
    }

    public static function unauthorizedRoles(): array
    {
        $cases = [];
        foreach (['guest', 'buyer', 'seller', 'logistics', 'rider', 'support'] as $role) {
            foreach (['approve', 'reject'] as $action) {
                $cases[] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_reviewers_cannot_change_metadata_or_history(string $role, string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->pending()->create();
        if ($role !== 'guest') {
            $actor = match ($role) {
                'buyer' => Buyer::factory()->create(),
                'seller' => Seller::factory()->create(),
                'logistics' => LogisticsProvider::factory()->create(),
                'rider' => Rider::factory()->create(),
                'support' => Admin::factory()->create(['role' => 'support']),
            };
            $this->actingAs($actor, $role === 'support' ? 'admin' : $role);
        }
        $response = $this->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Unauthorized decision']);
        if ($role === 'support') {
            $response->assertForbidden();
        } else {
            $response->assertRedirect(route('admin.login'));
        }
        $this->assertSame('pending', $seller->fresh()->status);
        $this->assertNull($seller->fresh()->approved_by);
        $this->assertNull($seller->fresh()->approved_at);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('decisions')]
    public function test_notification_waits_for_the_outer_transaction_commit(string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->pending()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        DB::beginTransaction();
        $this->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Incomplete permit'])->assertSessionHas('success');
        Notification::assertNothingSent();
        DB::commit();
        Notification::assertSentToTimes($seller, SellerRegistrationDecision::class, 1);
    }

    #[DataProvider('decisions')]
    public function test_outer_rollback_discards_the_decision_history_and_notification(string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->pending()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        DB::beginTransaction();
        $this->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Incomplete permit'])->assertSessionHas('success');
        DB::rollBack();
        $this->assertSame('pending', $seller->fresh()->status);
        $this->assertNull($seller->fresh()->approved_by);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }

    #[DataProvider('decisions')]
    public function test_audit_failure_rolls_back_the_seller_decision_and_sends_no_notification(string $action): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        Exceptions::fake();
        $seller = Seller::factory()->pending()->create();
        AccountActionHistory::creating(function (): void {
            throw new \RuntimeException('Audit unavailable.');
        });
        try {
            $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.registrations.seller.'.$action, $seller), ['rejection_reason' => 'Incomplete permit'])->assertServerError();
        } finally {
            AccountActionHistory::flushEventListeners();
        }
        $this->assertSame('pending', $seller->fresh()->status);
        $this->assertNull($seller->fresh()->approved_by);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_rejection_reason_validation_preserves_pending_state(): void
    {
        Notification::fake([SellerRegistrationDecision::class]);
        $seller = Seller::factory()->pending()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        foreach ([null, '', '  ', ['invalid'], str_repeat('x', 501)] as $reason) {
            $this->post(route('admin.registrations.seller.reject', $seller), ['rejection_reason' => $reason])->assertSessionHasErrors('rejection_reason');
            $this->assertSame('pending', $seller->fresh()->status);
        }
        $this->assertNull($seller->fresh()->approved_by);
        $this->assertDatabaseCount('account_action_histories', 0);
        Notification::assertNothingSent();
    }
}

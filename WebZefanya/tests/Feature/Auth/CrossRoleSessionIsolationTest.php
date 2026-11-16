<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrossRoleSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function accountActions(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'logistics'] as $role) {
            foreach (['suspend', 'reactivate'] as $action) {
                foreach ([false, true] as $encrypted) {
                    foreach (['json', 'php'] as $serialization) {
                        $cases[$role.'-'.$action.'-'.$serialization.'-'.(int) $encrypted] = [$role, $action, $encrypted, $serialization];
                    }
                }
            }
        }

        return $cases;
    }

    private function databaseSessions(bool $encrypted = false, string $serialization = 'json'): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions', 'session.encrypt' => $encrypted, 'session.serialization' => $serialization]);
    }

    private function seedSession(string $id, string $role, int $accountId = 500, ?int $rowUserId = 500, string $agent = 'same-browser'): string
    {
        $data = [Auth::guard($role)->getName() => $accountId, 'auth' => ['last_guard' => $role]];
        $serialized = config('session.serialization') === 'json' ? json_encode($data, JSON_THROW_ON_ERROR) : serialize($data);
        if (config('session.encrypt')) {
            $serialized = app('encrypter')->encrypt($serialized);
        }
        $payload = base64_encode($serialized);
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $rowUserId, 'ip_address' => '127.0.0.1', 'user_agent' => $agent, 'payload' => $payload, 'last_activity' => time()]);

        return $payload;
    }

    #[DataProvider('accountActions')]
    public function test_status_changes_revoke_only_matching_role_and_account_sessions(string $role, string $action, bool $encrypted, string $serialization): void
    {
        $this->databaseSessions($encrypted, $serialization);
        Notification::fake([AccountStatusChanged::class]);
        $accounts = [
            'buyer' => Buyer::factory()->create(['id' => 500, 'status' => 'approved', 'remember_token' => 'keep-buyer']),
            'seller' => Seller::factory()->create(['id' => 500, 'status' => 'approved', 'remember_token' => 'keep-seller']),
            'logistics' => LogisticsProvider::factory()->create(['id' => 500, 'status' => 'approved', 'remember_token' => 'keep-logistics']),
            'rider' => Rider::factory()->create(['id' => 500, 'status' => 'approved', 'remember_token' => 'keep-rider']),
            'admin' => Admin::factory()->create(['id' => 500, 'remember_token' => 'keep-admin']),
        ];
        $account = $accounts[$role];
        if ($action === 'reactivate') {
            $account->forceFill(['status' => 'suspended'])->save();
        }
        $unchanged = [];
        foreach ($accounts as $guard => $other) {
            $payload = $this->seedSession($guard.'-session', $guard);
            if ($guard !== $role) {
                $unchanged[$guard] = [$other->fresh()->getRawOriginal(), $payload];
            }
        }
        $this->seedSession('target-with-null-metadata', $role, 500, null);
        $differentIdPayload = $this->seedSession('different-account', $role, 501);
        DB::table('sessions')->insert(['id' => 'unidentifiable', 'user_id' => 500, 'payload' => 'not-a-session', 'last_activity' => time()]);
        $this->actingAs($accounts['admin'], 'admin')->post(route("admin.users.{$role}.{$action}", $account), ['reason' => 'Role-specific account security review.'])->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => $role.'-session']);
        foreach ($unchanged as $guard => [$attributes, $payload]) {
            $this->assertDatabaseHas('sessions', ['id' => $guard.'-session', 'payload' => $payload]);
            $this->assertSame($attributes, $accounts[$guard]->fresh()->getRawOriginal());
        }
        $this->assertDatabaseMissing('sessions', ['id' => 'target-with-null-metadata']);
        $this->assertDatabaseHas('sessions', ['id' => 'different-account', 'payload' => $differentIdPayload]);
        $this->assertDatabaseHas('sessions', ['id' => 'unidentifiable', 'payload' => 'not-a-session']);
        $this->assertNull($account->fresh()->remember_token);
        $expected = $action === 'suspend' ? 'suspended' : 'approved';
        $status = $account->fresh()->status;
        $this->assertSame($expected, $status instanceof \BackedEnum ? $status->value : $status);
        $this->assertDatabaseHas('account_action_histories', ['account_type' => $role, 'account_id' => 500, 'action' => $action === 'suspend' ? 'suspended' : 'reactivated']);
        Notification::assertSentTo($account, AccountStatusChanged::class);
    }

    public function test_buyer_login_cleanup_preserves_other_roles_with_the_same_id_and_fingerprint(): void
    {
        $this->databaseSessions();
        $buyer = Buyer::factory()->create(['id' => 500, 'status' => 'approved', 'google_id' => null]);
        foreach (['buyer', 'seller', 'logistics', 'rider', 'admin'] as $role) {
            $this->seedSession($role.'-old-session', $role);
        }
        $this->withHeader('User-Agent', 'same-browser')->post(route('unified.login'), ['email' => $buyer->email, 'password' => 'password'])->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'buyer-old-session']);
        foreach (['seller', 'logistics', 'rider', 'admin'] as $role) {
            $this->assertDatabaseHas('sessions', ['id' => $role.'-old-session']);
        }
    }

    public function test_suppressed_session_id_cannot_delete_another_roles_session(): void
    {
        $this->databaseSessions();
        $buyer = Buyer::factory()->create(['id' => 500, 'status' => 'approved', 'google_id' => null]);
        $payload = $this->seedSession('seller-suppressed', 'seller', agent: 'other-browser');
        $this->withSession(['session.suppressed_session_id' => 'seller-suppressed'])
            ->withHeader('User-Agent', 'current-browser')
            ->post(route('unified.login'), ['email' => $buyer->email, 'password' => 'password'])->assertRedirect(route('buyer.home'));

        $this->assertDatabaseHas('sessions', ['id' => 'seller-suppressed', 'payload' => $payload]);
    }

    public function test_admin_suspension_blocks_buyer_api_access_and_current_token_logout_still_works_after_reactivation(): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $admin = Admin::factory()->create();
        $token = $buyer->createToken('stage-one', ['buyer'])->plainTextToken;
        $this->actingAs($admin, 'admin')->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Security review.'])->assertSessionHas('success');
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withToken($token)->getJson('/api/buyer/profile')->assertForbidden();

        $this->actingAs($admin, 'admin')->post(route('admin.users.buyer.reactivate', $buyer), ['reason' => 'Review completed.'])->assertSessionHas('success');
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withToken($token)->getJson('/api/buyer/profile')->assertOk();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withToken($token)->postJson('/api/buyer/logout')->assertNoContent();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withToken($token)->getJson('/api/buyer/profile')->assertUnauthorized();
    }

    public function test_an_unrelated_seller_can_reuse_its_database_session_after_same_id_buyer_suspension(): void
    {
        $this->databaseSessions();
        $this->withoutVite();
        Notification::fake([AccountStatusChanged::class]);
        $buyer = Buyer::factory()->create(['id' => 500, 'status' => 'approved']);
        $seller = Seller::factory()->create(['id' => 500, 'status' => 'approved']);
        $admin = Admin::factory()->create(['id' => 500]);
        $sellerSession = str_repeat('s', 40);
        $buyerSession = str_repeat('b', 40);
        $this->seedSession($sellerSession, 'seller');
        $this->seedSession($buyerSession, 'buyer');
        $this->actingAs($admin, 'admin')->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Buyer-specific review.'])->assertSessionHas('success');

        // Resume a separate browser using its real persisted database session.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withCookie(config('session.cookie'), $sellerSession)->get(route('seller.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($seller, 'seller');

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->withCookie(config('session.cookie'), $buyerSession)->get(route('buyer.account.index'))->assertRedirect(route('home', ['login' => 1]));
        $this->assertGuest('buyer');
    }
}

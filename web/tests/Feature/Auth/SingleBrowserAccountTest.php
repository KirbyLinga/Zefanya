<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthRole;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SingleBrowserAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_visiting_a_buyer_page_ends_a_stale_seller_session_in_the_same_browser(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($buyer, 'buyer');
        $this->actingAs($seller, 'seller');

        $this->get(route('buyer.home'))->assertOk();

        $this->assertAuthenticated('buyer');
        $this->assertGuest('seller');
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['logistics'], ['admin']];
    }

    public static function roleSwitches(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'logistics', 'admin'] as $from) {
            foreach (['buyer', 'seller', 'logistics', 'admin'] as $to) {
                if ($from !== $to) {
                    $cases[$from.' to '.$to] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('roles')]
    public function test_role_routes_remove_other_persisted_guard_sessions(string $keep): void
    {
        $accounts = $this->accounts();
        $this->withSession(['auth.last_guard' => 'seller']);
        foreach ($accounts as $guard => $account) {
            session()->put(Auth::guard($guard)->getName(), $account->id);
        }
        $this->persistBrowserSession();

        $this->get($this->protectedUrl($keep))->assertOk();
        $this->assertOnlyRoleIsAuthenticated($keep);

        // Resolve new guard objects, so cached users cannot hide leftover login keys.
        $this->persistBrowserSession();
        $this->get($this->protectedUrl($keep))->assertOk();
        $this->assertOnlyRoleIsAuthenticated($keep);

        foreach (array_keys($accounts) as $other) {
            if ($other === $keep) {
                continue;
            }
            $this->persistBrowserSession();
            $this->get($this->protectedUrl($other))
                ->assertRedirect($other === 'admin' ? route('admin.login') : route('home', ['login' => 1]));
            $this->assertOnlyRoleIsAuthenticated($keep);
        }
    }

    #[DataProvider('roleSwitches')]
    public function test_switching_roles_requires_logout_and_never_leaves_two_accounts_signed_in(string $from, string $to): void
    {
        $accounts = $this->accounts();
        $this->post($this->loginUrl($from), ['email' => $accounts[$from]->email, 'password' => 'password'])
            ->assertRedirect(AuthRole::from($from)->dashboardUrl());
        $this->assertOnlyRoleIsAuthenticated($from);
        $this->persistBrowserSession();

        $this->post($this->loginUrl($to), ['email' => $accounts[$to]->email, 'password' => 'password'])
            ->assertRedirect(AuthRole::from($from)->dashboardUrl());
        $this->assertOnlyRoleIsAuthenticated($from);
        $this->persistBrowserSession();

        $this->post(route('unified.logout'))->assertRedirect(route('home'));
        foreach (array_keys($accounts) as $guard) {
            $this->assertGuest($guard);
        }
        $this->persistBrowserSession();

        $this->post($this->loginUrl($to), ['email' => $accounts[$to]->email, 'password' => 'password'])
            ->assertRedirect(AuthRole::from($to)->dashboardUrl());
        $this->persistBrowserSession();
        $this->get($this->protectedUrl($to))->assertOk();
        $this->assertOnlyRoleIsAuthenticated($to);
    }

    public static function logoutRoutes(): array
    {
        return [['unified.logout', 'home'], ['buyer.logout', 'home'], ['seller.logout', 'home'], ['admin.logout', 'admin.login']];
    }

    #[DataProvider('logoutRoutes')]
    public function test_every_logout_endpoint_invalidates_all_persisted_guards(string $logout, string $destination): void
    {
        $accounts = $this->accounts();
        $this->withSession(['auth.last_guard' => 'admin']);
        foreach ($accounts as $guard => $account) {
            session()->put(Auth::guard($guard)->getName(), $account->id);
        }
        $this->persistBrowserSession();
        $oldId = session()->getId();
        $oldToken = session()->token();

        $this->post(route($logout))->assertRedirect(route($destination));

        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->persistBrowserSession();
        foreach (array_keys($accounts) as $guard) {
            $this->assertGuest($guard);
        }
    }

    #[DataProvider('roles')]
    public function test_password_change_revokes_the_persisted_role_session(string $guard): void
    {
        $account = $this->accounts()[$guard];
        $this->post($this->loginUrl($guard), ['email' => $account->email, 'password' => 'password'])
            ->assertRedirect(AuthRole::from($guard)->dashboardUrl());
        $this->persistBrowserSession();
        $account->forceFill(['password' => 'ChangedPassword123!'])->save();
        $this->persistBrowserSession();

        $this->get($this->protectedUrl($guard))
            ->assertRedirect($guard === 'admin' ? route('admin.login') : route('home', ['login' => 1]));
        $this->assertGuest($guard);
    }

    public function test_revoked_admin_session_cannot_perform_an_account_action(): void
    {
        Notification::fake([AccountStatusChanged::class]);
        $accounts = $this->accounts();
        $admin = $accounts['admin'];
        $seller = $accounts['seller'];
        $this->post(route('admin.login.post'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->persistBrowserSession();
        $this->get(route('admin.dashboard'))->assertOk();
        $admin->forceFill(['password' => 'ChangedPassword123!'])->save();
        $this->persistBrowserSession();

        $this->post(route('admin.users.seller.suspend', $seller), ['reason' => 'Must not be executed.'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('sellers', ['id' => $seller->id, 'status' => 'approved']);
        $this->assertDatabaseCount('account_action_histories', 0);
        $this->assertGuest('admin');
        Notification::assertNothingSent();
    }

    private function accounts(): array
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);
        $this->withoutVite();

        return [
            'buyer' => Buyer::factory()->create(['status' => 'approved', 'google_id' => null]),
            'seller' => Seller::factory()->create(['status' => 'approved', 'google_id' => null]),
            'logistics' => LogisticsProvider::factory()->create(['status' => 'approved', 'google_id' => null]),
            'admin' => Admin::factory()->create(['password' => 'password']),
        ];
    }

    private function loginUrl(string $guard): string
    {
        return $guard === 'admin' ? route('admin.login.post') : route('unified.login');
    }

    private function protectedUrl(string $guard): string
    {
        return $guard === 'buyer' ? route('buyer.account.index') : AuthRole::from($guard)->dashboardUrl();
    }

    private function persistBrowserSession(): void
    {
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
        Auth::forgetGuards();
    }

    private function assertOnlyRoleIsAuthenticated(string $keep): void
    {
        foreach (['buyer', 'seller', 'logistics', 'admin'] as $guard) {
            if ($guard === $keep) {
                $this->assertAuthenticated($guard);
            } else {
                $this->assertGuest($guard);
                $this->assertFalse(session()->has(Auth::guard($guard)->getName()));
            }
        }
    }
}

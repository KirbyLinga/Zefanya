<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RedirectIfAuthenticatedRole;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Support\AccountUnderReview;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PendingAccountAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function roles(): array
    {
        return [['seller'], ['logistics'], ['rider']];
    }

    private function pendingAccount(string $role): mixed
    {
        $class = match ($role) {
            'seller' => Seller::class,
            'logistics' => LogisticsProvider::class,
            'rider' => Rider::class,
        };

        return $class::factory()->create(['status' => 'pending', 'email_verified_at' => now(), 'password' => 'Pending@2026']);
    }

    #[DataProvider('roles')]
    public function test_pending_login_keeps_the_session_and_shows_review_notice(string $role): void
    {
        $account = $this->pendingAccount($role);
        $this->withSession(['url.intended' => route('buyer.home')])
            ->post(route('unified.login'), ['email' => $account->email, 'password' => 'Pending@2026'])
            ->assertRedirect(route($role.'.dashboard'))->assertSessionMissing('url.intended')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($account, $role);
        $this->get(route($role.'.dashboard'))->assertForbidden()->assertSee('Your account is under review');
        $this->assertAuthenticatedAs($account, $role);
        $this->post(route('unified.logout'))->assertRedirect(route('home'));
        $this->assertGuest($role);
    }

    public static function blockedOperations(): array
    {
        return [
            ['seller', 'GET', 'seller.products.index'], ['seller', 'POST', 'seller.products.store'],
            ['seller', 'GET', 'seller.orders.index'], ['logistics', 'GET', 'logistics.riders.index'],
            ['logistics', 'POST', 'logistics.riders.store'], ['logistics', 'GET', 'logistics.incoming-parcels.index'],
            ['rider', 'GET', 'rider.pickups.index'], ['rider', 'GET', 'rider.deliveries.index'],
            ['rider', 'POST', 'rider.scan.verify'],
        ];
    }

    #[DataProvider('blockedOperations')]
    public function test_pending_accounts_cannot_use_operational_endpoints(string $role, string $method, string $route): void
    {
        $account = $this->pendingAccount($role);
        $this->actingAs($account, $role)->json($method, route($route))->assertForbidden()->assertJsonPath('message', AccountUnderReview::MESSAGE);
        $this->assertAuthenticatedAs($account, $role);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('riders', $role === 'rider' ? 1 : 0);
    }

    #[DataProvider('roles')]
    public function test_wrong_password_does_not_create_a_pending_session(string $role): void
    {
        $account = $this->pendingAccount($role);
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Wrong@2026'])->assertSessionHasErrors('email');
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_pending_status_does_not_bypass_email_verification(string $role): void
    {
        $account = $this->pendingAccount($role);
        $account->forceFill(['email_verified_at' => null])->save();
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Pending@2026'])->assertSessionHasErrors('email');
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_pending_login_ends_other_role_sessions(string $role): void
    {
        $account = $this->pendingAccount($role);
        // Simulate an old guard remaining on the browser when credentials are submitted.
        $buyer = Buyer::factory()->create();
        $this->withoutMiddleware(RedirectIfAuthenticatedRole::class)->actingAs($buyer, 'buyer');
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Pending@2026'])->assertRedirect(route($role.'.dashboard'));
        $this->assertAuthenticatedAs($account, $role);
        $this->assertGuest('buyer');
    }

    public function test_legacy_seller_login_keeps_a_pending_session(): void
    {
        $seller = $this->pendingAccount('seller');
        $this->post(route('seller.login.post'), ['email' => $seller->email, 'password' => 'Pending@2026'])->assertRedirect(route('seller.dashboard'));
        $this->assertAuthenticatedAs($seller, 'seller');
        $this->get(route('seller.dashboard'))->assertForbidden()->assertSee('Your account is under review');
    }
}

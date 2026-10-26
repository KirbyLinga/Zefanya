<?php

namespace Tests\Unit\Admin;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAccountControllerSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Keep the session-driver contract explicit for these tests.
        Config::shouldReceive('get')->andReturnUsing(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'session.table' => 'sessions',
                'session.driver' => 'database',
                'app.faker_locale' => 'en_US',
                default => value($default),
            };
        });

        Config::shouldReceive('offsetExists')->andReturnUsing(static function (string $key): bool {
            return in_array($key, [
                'session.table',
                'session.driver',
                'auth.defaults.guard',
                'auth.guards',
                'auth.providers',
                'auth.passwords',
                'auth.password_timeout',
                'app.faker_locale',
            ], true);
        });

        Config::shouldReceive('offsetGet')->andReturnUsing(static function (string $key) {
            return match ($key) {
                'auth.defaults.guard' => 'web',
                'auth.guards' => [
                    'web' => ['driver' => 'session', 'provider' => 'users'],
                    'buyer' => ['driver' => 'session', 'provider' => 'buyers'],
                    'seller' => ['driver' => 'session', 'provider' => 'sellers'],
                    'logistics' => ['driver' => 'session', 'provider' => 'logistics'],
                ],
                'auth.providers' => [
                    'users' => ['driver' => 'eloquent', 'model' => User::class],
                    'buyers' => ['driver' => 'eloquent', 'model' => Buyer::class],
                    'sellers' => ['driver' => 'eloquent', 'model' => Seller::class],
                    'logistics' => ['driver' => 'eloquent', 'model' => LogisticsProvider::class],
                ],
                'auth.passwords' => [
                    'users' => ['provider' => 'users',    'table' => 'password_reset_tokens',          'expire' => 60, 'throttle' => 60],
                    'buyers' => ['provider' => 'buyers',   'table' => 'password_reset_tokens_buyers',   'expire' => 60, 'throttle' => 60],
                    'sellers' => ['provider' => 'sellers',  'table' => 'password_reset_tokens_sellers', 'expire' => 60, 'throttle' => 60],
                    'logistics' => ['provider' => 'logistics', 'table' => 'password_reset_tokens_logistics', 'expire' => 60, 'throttle' => 60],
                ],
                'auth.password_timeout' => 10800,
                'session.table' => 'sessions',
                'session.driver' => 'database',
                'app.faker_locale' => 'en_US',
                default => null,
            };
        });

        Config::shouldReceive('offsetSet')->andReturnUsing(static function (string $key, mixed $value): void {
            // no-op
        });

        Config::shouldReceive('offsetUnset')->andReturnUsing(static function (string $key): void {
            // no-op
        });

        Config::shouldReceive('shouldUse')->andReturnUsing(static function (string $guard) {
            return in_array($guard, ['web', 'buyer', 'seller', 'logistics'], true);
        });
    }

    #[Test]
    public function suspending_a_buyer_deletes_its_active_database_sessions(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved']);

        $this->seedSessionFor($buyer, 'abc-session');
        $this->seedSessionFor($buyer, 'xyz-session');

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'abc-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'xyz-session']);
        $this->assertDatabaseHas('sessions', [
            'id' => 'other-users-session',
            'user_id' => 99,
        ]);
    }

    #[Test]
    public function suspending_a_buyer_clears_a_present_remember_token(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'remember_token' => 'rotate-me',
        ]);

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer))
            ->assertSessionHas('success');

        $buyer->refresh();
        $this->assertNull($buyer->getRememberToken());
    }

    #[Test]
    public function suspending_a_buyer_without_remember_token_does_not_fail(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'remember_token' => null,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer))
            ->assertSessionHas('success');

        $buyer->refresh();
        $this->assertNull($buyer->getRememberToken());
    }

    #[Test]
    public function suspending_a_buyer_with_a_non_integer_id_is_safe(): void
    {
        // Buyer factories here use surrogate keys; this guard is mostly a
        // defensive check against any future custom-key identity models.
        $buyer = Buyer::factory()->create(['status' => 'approved']);

        $this->seedSessionFor($buyer, 'safe-session');

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sessions', ['id' => 'safe-session']);
    }

    #[Test]
    public function suspending_a_buyer_when_session_driver_is_not_database_is_safe(): void
    {
        Config::shouldReceive('get')
            ->with('session.driver')
            ->andReturn('file');

        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $this->seedSessionFor($buyer, 'file-session');

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sessions', ['id' => 'file-session']);
    }

    #[Test]
    public function reactivating_a_buyer_clears_leftover_sessions_and_remember_token(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'suspended']);

        // Sessions and remember token that were left behind while suspended.
        $this->seedSessionFor($buyer, 'zombie-session');
        $buyer->setRememberToken('stale-remember');
        $buyer->saveQuietly();

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.reactivate', $buyer))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'zombie-session']);
        $buyer->refresh();
        $this->assertNull($buyer->getRememberToken());
        $this->assertSame('approved', $buyer->status);
    }

    #[Test]
    public function suspending_a_seller_clears_its_database_sessions_and_remember_token(): void
    {
        $seller = Seller::factory()->create([
            'status' => 'approved',
            'remember_token' => 'rotate-seller',
        ]);
        $this->seedSessionFor($seller, 'seller-session');

        $this->actingAs($seller, 'seller')
            ->post(route('admin.users.seller.suspend', $seller))
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertNull($seller->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['id' => 'seller-session']);
    }

    #[Test]
    public function suspending_a_logistics_provider_clears_its_database_sessions_and_remember_token(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'remember_token' => 'rotate-logistics',
        ]);
        $this->seedSessionFor($provider, 'logistics-session');

        $this->actingAs($provider, 'logistics')
            ->post(route('admin.users.logistics.suspend', $provider))
            ->assertSessionHas('success');

        $provider->refresh();
        $this->assertNull($provider->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['id' => 'logistics-session']);
    }

    #[Test]
    public function suspending_a_seller_without_remember_token_clears_sessions_only(): void
    {
        $seller = Seller::factory()->create([
            'status' => 'approved',
            'remember_token' => null,
        ]);
        $this->seedSessionFor($seller, 'seller-session-no-token');

        $this->actingAs($seller, 'seller')
            ->post(route('admin.users.seller.suspend', $seller))
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertNull($seller->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['id' => 'seller-session-no-token']);
    }

    #[Test]
    public function suspending_a_logistics_provider_without_remember_token_clears_sessions_only(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'remember_token' => null,
        ]);
        $this->seedSessionFor($provider, 'logistics-session-no-token');

        $this->actingAs($provider, 'logistics')
            ->post(route('admin.users.logistics.suspend', $provider))
            ->assertSessionHas('success');

        $provider->refresh();
        $this->assertNull($provider->getRememberToken());
        $this->assertDatabaseMissing('sessions', ['id' => 'logistics-session-no-token']);
    }

    private function seedSessionFor($account, string $sessionId): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $account->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);
    }
}

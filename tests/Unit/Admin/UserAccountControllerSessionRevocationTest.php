<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\Admin\UserAccountController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

        // The controller only clears database session rows when the database
        // session driver is active, and phpunit.xml defaults to the array
        // driver. Override the real config instead of mocking the Config
        // facade: a facade mock here leaks into every later test because the
        // framework reads config for the database connection itself.
        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
        ]);

        // The suspend/reactivate routes sit behind auth:admin +
        // admin.role:admin,moderator, so an admin has to be signed in
        // alongside the account being acted upon.
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    #[Test]
    public function suspending_a_buyer_deletes_its_active_database_sessions(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved']);

        $this->seedSessionFor($buyer, 'abc-session');
        $this->seedSessionFor($buyer, 'xyz-session');

        // Control row: revoking one account's sessions must never touch
        // another account that happens to be signed in.
        $this->seedSessionRow(99, 'other-users-session');

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Account status reviewed by Admin.'])
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
            ->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $buyer->refresh();
        // Assert the stored column, not getRememberToken(): Laravel casts that
        // accessor to string, so a NULL column reads back as ''.
        $this->assertNull($buyer->remember_token);
    }

    #[Test]
    public function suspending_a_buyer_without_remember_token_does_not_fail(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'remember_token' => null,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $buyer->refresh();
        // Assert the stored column, not getRememberToken(): Laravel casts that
        // accessor to string, so a NULL column reads back as ''.
        $this->assertNull($buyer->remember_token);
    }

    #[Test]
    public function a_non_integer_identifier_is_not_used_against_the_sessions_table(): void
    {
        // Buyer factories here use surrogate keys; this guard is a defensive
        // check against any future custom-key identity model. Exercise the
        // guard directly with a string identifier instead of pretending an
        // ordinary buyer suspension must leave its sessions intact.
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $this->seedSessionFor($buyer, 'safe-session');

        // Eloquent casts integer keys, so a real Buyer cannot surface a
        // non-numeric identifier. Use a subclass to exercise the guard.
        $stringKeyed = new class extends Buyer
        {
            public function getAuthIdentifier(): mixed
            {
                return 'buyer-uuid-1';
            }
        };
        $stringKeyed->forceFill(['remember_token' => 'keep-me']);

        $controller = app(UserAccountController::class);
        (new \ReflectionMethod($controller, 'revokeAccountSessionsAndRememberToken'))
            ->invoke($controller, $stringKeyed);

        $this->assertDatabaseHas('sessions', ['id' => 'safe-session']);
        $this->assertSame('keep-me', $stringKeyed->remember_token);
    }

    #[Test]
    public function suspending_a_buyer_when_session_driver_is_not_database_is_safe(): void
    {
        config(['session.driver' => 'file']);

        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $this->seedSessionFor($buyer, 'file-session');

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.users.buyer.suspend', $buyer), ['reason' => 'Account status reviewed by Admin.'])
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
            ->post(route('admin.users.buyer.reactivate', $buyer), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'zombie-session']);
        $buyer->refresh();
        // Assert the stored column, not getRememberToken(): Laravel casts that
        // accessor to string, so a NULL column reads back as ''.
        $this->assertNull($buyer->remember_token);
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
            ->post(route('admin.users.seller.suspend', $seller), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertNull($seller->remember_token);
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
            ->post(route('admin.users.logistics.suspend', $provider), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $provider->refresh();
        $this->assertNull($provider->remember_token);
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
            ->post(route('admin.users.seller.suspend', $seller), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertNull($seller->remember_token);
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
            ->post(route('admin.users.logistics.suspend', $provider), ['reason' => 'Account status reviewed by Admin.'])
            ->assertSessionHas('success');

        $provider->refresh();
        $this->assertNull($provider->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'logistics-session-no-token']);
    }

    private function seedSessionFor($account, string $sessionId): void
    {
        $this->seedSessionRow($account->getAuthIdentifier(), $sessionId);
        $guard = match (true) {
            $account instanceof Buyer => 'buyer',
            $account instanceof Seller => 'seller',
            $account instanceof LogisticsProvider => 'logistics',
        };
        $attributes = [Auth::guard($guard)->getName() => $account->getAuthIdentifier()];
        $serialized = config('session.serialization') === 'json' ? json_encode($attributes, JSON_THROW_ON_ERROR) : serialize($attributes);
        if (config('session.encrypt')) {
            $serialized = app('encrypter')->encrypt($serialized);
        }
        DB::table('sessions')->where('id', $sessionId)->update(['payload' => base64_encode($serialized)]);
    }

    private function seedSessionRow(int|string $userId, string $sessionId): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);
    }
}

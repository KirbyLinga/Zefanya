<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class BuyerRiderApiAuthenticationTest extends TestCase
{
    use CreatesAssignableFleet;
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['buyer'], ['rider']];
    }

    private function account(string $role): Buyer|Rider
    {
        if ($role === 'buyer') {
            return Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'password' => Hash::make('ApiPassword@2026')]);
        }

        $courier = $this->assignableCourier(LogisticsProvider::factory()->create());
        $rider = Rider::findOrFail($courier->rider_id);
        $rider->forceFill(['password' => Hash::make('ApiPassword@2026')])->save();

        return $rider;
    }

    private function freshAuthentication(): void
    {
        // Resolve tokens afresh between simulated HTTP requests.
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    #[DataProvider('roles')]
    public function test_valid_login_is_stateless_and_issues_a_sixty_minute_role_token(string $role): void
    {
        $this->travelTo(now()->startOfSecond());
        $account = $this->account($role);
        $response = $this->postJson("/api/{$role}/login", ['email' => strtoupper($account->email), 'password' => 'ApiPassword@2026']);
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.role', $role)
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_in', 3600);
        $this->assertSame([], $response->headers->getCookies());
        $token = $account->tokens()->sole();
        $this->assertSame([$role], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addMinutes(60)));
        $this->assertGuest($role);
        $this->freshAuthentication();
        $this->withToken($response->json('data.token'))->getJson("/api/{$role}/profile")->assertOk();
    }

    #[DataProvider('roles')]
    public function test_logout_revokes_only_current_token_and_preserves_web_session(string $role): void
    {
        $account = $this->account($role);
        $current = $account->createToken('current', [$role]);
        $other = $account->createToken('other', [$role]);
        $sessionKey = Auth::guard($role)->getName();
        $this->withSession([$sessionKey => $account->id, 'unrelated' => 'preserved']);
        $this->withToken($current->plainTextToken)->postJson("/api/{$role}/logout")->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertSame($account->id, session($sessionKey));
        $this->assertSame('preserved', session('unrelated'));
        $this->freshAuthentication();
        $this->withToken($current->plainTextToken)->getJson("/api/{$role}/profile")->assertUnauthorized()->assertJsonPath('success', false);
        $this->freshAuthentication();
        $this->withToken($other->plainTextToken)->getJson("/api/{$role}/profile")->assertOk();
    }

    public static function ineligibleAccounts(): array
    {
        $cases = [];
        foreach (['buyer', 'rider'] as $role) {
            foreach (['pending', 'rejected', 'suspended', 'unverified', 'deactivated', 'email_unverified'] as $state) {
                $cases["{$role}:{$state}"] = [$role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_ineligible_accounts_cannot_login_or_use_an_existing_token(string $role, string $state): void
    {
        $account = $this->account($role);
        $token = $account->createToken('existing', [$role])->plainTextToken;
        $this->withToken($token)->getJson("/api/{$role}/profile")->assertOk();
        $account->forceFill($state === 'email_unverified' ? ['email_verified_at' => null] : ['status' => $state])->save();
        $this->freshAuthentication();
        $this->withToken($token)->getJson("/api/{$role}/profile")->assertForbidden()->assertJsonPath('success', false);
        $this->freshAuthentication();
        $this->postJson("/api/{$role}/login", ['email' => $account->email, 'password' => 'ApiPassword@2026'])
            ->assertForbidden()->assertJsonPath('success', false);
        $this->assertSame(1, $account->tokens()->count());
    }

    #[DataProvider('roles')]
    public function test_sixth_bad_login_is_throttled(string $role): void
    {
        $payload = ['email' => 'missing@example.com', 'password' => 'incorrect'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson("/api/{$role}/login", $payload)->assertUnauthorized()->assertJsonPath('success', false);
        }
        $this->postJson("/api/{$role}/login", $payload)->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('success', false);
        $this->postJson("/api/{$role}/login", ['email' => 'different@example.com', 'password' => 'incorrect'])->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_wrong_role_credentials_and_tokens_are_rejected(string $role): void
    {
        $account = $this->account($role);
        $opposite = $role === 'buyer' ? 'rider' : 'buyer';
        $this->postJson("/api/{$opposite}/login", ['email' => $account->email, 'password' => 'ApiPassword@2026'])->assertUnauthorized();
        foreach ([[$role], [$opposite], ['*']] as $abilities) {
            $token = $account->createToken('wrong-role', $abilities);
            $this->freshAuthentication();
            $this->withToken($token->plainTextToken)->getJson("/api/{$opposite}/profile")->assertForbidden();
            $this->freshAuthentication();
            $this->withToken($token->plainTextToken)->postJson("/api/{$opposite}/logout")->assertForbidden();
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        }
    }

    #[DataProvider('roles')]
    public function test_missing_invalid_and_expired_tokens_are_unauthorized(string $role): void
    {
        $account = $this->account($role);
        $this->getJson("/api/{$role}/profile")->assertUnauthorized();
        $this->freshAuthentication();
        $this->withToken('invalid')->getJson("/api/{$role}/profile")->assertUnauthorized();
        $token = $account->createToken('expired', [$role], now()->subMinute());
        $this->freshAuthentication();
        $this->withToken($token->plainTextToken)->getJson("/api/{$role}/profile")->assertUnauthorized();
        $oldToken = $account->createToken('old', [$role]);
        $oldToken->accessToken->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->freshAuthentication();
        $this->withToken($oldToken->plainTextToken)->getJson("/api/{$role}/profile")->assertUnauthorized();
    }

    public function test_rider_operational_eligibility_is_rechecked_for_existing_tokens(): void
    {
        $rider = $this->account('rider');
        $token = $rider->createToken('existing', ['rider'])->plainTextToken;
        $this->withToken($token)->getJson('/api/rider/profile')->assertOk();
        $rider->forceFill(['station_hub_id' => null])->save();
        $this->freshAuthentication();
        $this->withToken($token)->getJson('/api/rider/profile')->assertForbidden();
        $this->postJson('/api/rider/login', ['email' => $rider->email, 'password' => 'ApiPassword@2026'])->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_incorrect_password_does_not_issue_a_token(string $role): void
    {
        $account = $this->account($role);
        $this->postJson("/api/{$role}/login", ['email' => $account->email, 'password' => 'incorrect'])
            ->assertUnauthorized()->assertJsonPath('success', false);
        $this->assertSame(0, $account->tokens()->count());
        $this->assertGuest($role);
    }

    #[DataProvider('roles')]
    public function test_active_accounts_remain_eligible_and_missing_ability_is_forbidden(string $role): void
    {
        $account = $this->account($role);
        $account->forceFill(['status' => 'active'])->save();
        $this->postJson("/api/{$role}/login", ['email' => $account->email, 'password' => 'ApiPassword@2026'])->assertOk();
        $token = $account->createToken('no-abilities', []);
        $this->freshAuthentication();
        $this->withToken($token->plainTextToken)->getJson("/api/{$role}/profile")->assertForbidden();
    }

    public function test_losing_operational_station_or_courier_blocks_rider_token_access(): void
    {
        $rider = $this->account('rider');
        $token = $rider->createToken('existing', ['rider'])->plainTextToken;
        $station = $rider->station;
        $station->forceFill(['is_active' => false])->save();
        $this->withToken($token)->getJson('/api/rider/profile')->assertForbidden();
        $station->forceFill(['is_active' => true])->save();
        $rider->courier()->delete();
        $this->freshAuthentication();
        $this->withToken($token)->getJson('/api/rider/profile')->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_api_login_preserves_an_existing_web_account_session(string $role): void
    {
        $account = $this->account($role);
        $existing = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $key = Auth::guard('buyer')->getName();
        $this->withSession([$key => $existing->id]);
        $this->postJson("/api/{$role}/login", ['email' => $account->email, 'password' => 'ApiPassword@2026'])->assertOk();
        $this->assertSame($existing->id, session($key));
        $this->freshAuthentication();
        $this->assertAuthenticatedAs($existing, 'buyer');
    }

    public function test_login_ip_limit_applies_across_buyer_and_rider_endpoints(): void
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $role = $attempt % 2 === 0 ? 'buyer' : 'rider';
            $this->postJson("/api/{$role}/login", ['email' => "missing{$attempt}@example.com", 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->postJson('/api/rider/login', ['email' => 'another@example.com', 'password' => 'incorrect'])
            ->assertTooManyRequests()->assertJsonPath('success', false)->assertHeader('Retry-After');
    }
}

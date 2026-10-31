<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function another_role_must_log_out_before_a_second_role_can_sign_in(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create(['status' => 'approved']);
        $rider = Rider::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller');
        $this->actingAs($rider, 'rider');

        // Guest-only login: while another role is still signed in, the login
        // request is redirected to that role's dashboard instead of opening a
        // second role session in the same browser.
        $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('seller.dashboard'));

        $this->assertGuest('buyer');

        // Explicit logout ends every guard, after which the buyer can sign in
        // on its own and no other role stays authenticated.
        $this->post(route('unified.logout'))->assertRedirect(route('home'));

        $this->assertGuest('seller');
        $this->assertGuest('rider');

        $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('buyer.home'));

        $this->assertAuthenticated('buyer');
        $this->assertGuest('seller');
        $this->assertGuest('rider');
    }

    #[Test]
    public function buyer_login_does_not_touch_a_different_browsers_session(): void
    {
        $this->useDatabaseSessions();

        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        $this->seedSession($buyer->getAuthIdentifier(), 'buyer-existing-session', 'other-browser');

        $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('buyer.home'));

        $this->assertDatabaseHas('sessions', ['id' => 'buyer-existing-session']);
    }

    #[Test]
    public function buyer_login_suppresses_the_existing_session_on_second_login(): void
    {
        $this->useDatabaseSessions();

        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        // Same browser means the same IP + User-Agent fingerprint.
        $this->seedSession($buyer->getAuthIdentifier(), 'buyer-existing-session', 'same-browser');

        $this->withHeader('User-Agent', 'same-browser')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'buyer-existing-session']);
    }

    #[Test]
    public function buyer_login_without_a_previous_session_is_still_fully_accepted(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        $response = $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'no-previous-session-at-all']);
    }

    #[Test]
    public function buyer_login_deletes_a_session_recorded_as_suppressed_for_the_same_account(): void
    {
        $this->useDatabaseSessions();

        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        // Deliberately a different fingerprint from the login request so the
        // normal same-browser sweep cannot match it; only the suppressed-id
        // branch may delete this row.
        $this->seedSession($buyer->getAuthIdentifier(), 'trace-session-before', 'trace-browser-old');

        $this->withSession([
            'session.ip_address' => '127.0.0.1',
            'session.user_agent' => 'trace-browser-current',
            'session.suppressed_session_id' => 'trace-session-before',
        ])->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'trace-session-before']);
    }

    private function useDatabaseSessions(): void
    {
        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
        ]);
    }

    private function seedSession(int|string $userId, string $sessionId, string $userAgent): void
    {
        // These are Buyer login fixtures. Persist the same identity-bearing
        // payload as Laravel, rather than an unidentifiable placeholder.
        $attributes = [Auth::guard('buyer')->getName() => $userId];
        $serialized = config('session.serialization') === 'json' ? json_encode($attributes, JSON_THROW_ON_ERROR) : serialize($attributes);
        if (config('session.encrypt')) {
            $serialized = app('encrypter')->encrypt($serialized);
        }
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => $userAgent,
            'payload' => base64_encode($serialized),
            'last_activity' => time(),
        ]);
    }
}

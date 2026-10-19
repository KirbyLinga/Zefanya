<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google" for buyers. The flow LINKS an existing buyer row to a
 * Google account — it never creates one, and it applies the same approval gate
 * as password sign-in.
 */
class GoogleBuyerLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_google_login_links_the_account_and_signs_the_buyer_in(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'buyer@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('buyer@gmail.com', 'google-buyer-1');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.home'));
        $this->assertAuthenticated('buyer');
        $this->assertSame('google-buyer-1', $buyer->fresh()->google_id);
    }

    public function test_unknown_google_email_is_sent_to_registration_with_an_error(): void
    {
        $this->fakeGoogleUser('nobody@gmail.com', 'google-unknown');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('register.type'));
        $response->assertSessionHas('auth.status_message', GoogleAuthController::NO_ACCOUNT_MESSAGE);
        $this->assertGuest('buyer');
        // No auto-registration: the role tables are untouched.
        $this->assertDatabaseCount('buyers', 0);
    }

    public function test_buyer_awaiting_approval_is_linked_but_cannot_sign_in_with_google(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'pending@gmail.com',
            'status' => 'pending',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('pending@gmail.com', 'google-buyer-2');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'buyer']));

        $response->assertRedirect(route('register.type'));
        $response->assertSessionHas(
            'auth.status_message',
            'Your buyer registration is still awaiting administrator approval.',
        );
        $this->assertGuest('buyer');
        // The Google identity is real, so it is still linked — the session is
        // what the approval gate blocks.
        $this->assertSame('google-buyer-2', $buyer->fresh()->google_id);
    }

    private function fakeGoogleUser(string $email, string $id): void
    {
        $googleUser = (new GoogleUser)->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google Buyer',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

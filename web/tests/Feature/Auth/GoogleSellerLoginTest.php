<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google" for sellers (role-scoped pair, /auth/google/seller/*).
 */
class GoogleSellerLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_google_login_links_the_account_and_signs_the_seller_in(): void
    {
        $seller = Seller::factory()->create([
            'email' => 'seller@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('seller@gmail.com', 'google-seller-1');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'seller']));

        $response->assertRedirect(route('seller.dashboard'));
        $this->assertAuthenticated('seller');
        $this->assertSame('google-seller-1', $seller->fresh()->google_id);
    }

    public function test_unknown_google_email_is_sent_back_to_login_with_an_error(): void
    {
        $this->fakeGoogleUser('nobody@gmail.com', 'google-unknown');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'seller']));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas('auth.status_message', GoogleAuthController::NO_ACCOUNT_MESSAGE);
        $this->assertGuest('seller');
        $this->assertDatabaseCount('sellers', 0);
    }

    public function test_seller_awaiting_approval_cannot_sign_in_with_google(): void
    {
        $seller = Seller::factory()->pending()->create([
            'email' => 'pending-seller@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('pending-seller@gmail.com', 'google-seller-2');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'seller']));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'Your seller account is still pending administrator approval.',
        );
        $this->assertGuest('seller');
        $this->assertSame('google-seller-2', $seller->fresh()->google_id);
    }

    private function fakeGoogleUser(string $email, string $id): void
    {
        $googleUser = (new GoogleUser)->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google Seller',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

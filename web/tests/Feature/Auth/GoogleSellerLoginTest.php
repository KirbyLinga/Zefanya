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

    public function test_seller_awaiting_approval_can_sign_in_only_to_the_review_notice(): void
    {
        $seller = Seller::factory()->pending()->create([
            'email' => 'pending-seller@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('pending-seller@gmail.com', 'google-seller-2');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'seller']));

        $response->assertRedirect(route('seller.dashboard'));
        $this->assertAuthenticated('seller');
        $this->get(route('seller.dashboard'))->assertForbidden()->assertSee('Your account is under review');
        $this->assertSame('google-seller-2', $seller->fresh()->google_id);
    }

    public function test_rejected_seller_google_login_shows_the_saved_reason(): void
    {
        $account = Seller::factory()->create(['status' => 'rejected', 'rejection_reason' => 'Business permit is unreadable.', 'email' => 'rejected-seller@gmail.com', 'google_id' => null]);
        $this->fakeGoogleUser($account->email, 'rejected-seller-id');
        $this->get(route('auth.google.role.callback', ['role' => 'seller']))->assertRedirect(route('home', ['login' => 1]))
            ->assertSessionHas('auth.status_message', fn ($message) => str_contains($message, 'Reason: Business permit is unreadable.'));
        $this->assertGuest('seller');
        $this->get(route('home', ['login' => 1]))->assertOk()->assertSeeText('Reason: Business permit is unreadable.');
    }

    private function fakeGoogleUser(string $email, string $id): void
    {
        $googleUser = (new GoogleUser)->setRaw([
            'sub' => $id,
            'email' => $email,
            'email_verified' => true,
        ])->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google Seller',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

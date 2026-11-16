<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_google_buyer_callback_ignores_stale_admin_intended_url(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'buyer-intended@gmail.com',
            'status' => 'approved',
            'google_id' => null,
        ]);

        // Simulate a prior attempt to open an admin page while logged out.
        $this->withSession(['url.intended' => '/admin/dashboard']);

        $this->fakeGoogleUser('buyer-intended@gmail.com', 'google-buyer-intended');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.home'));
        $this->assertAuthenticated('buyer');
        $this->assertGuest('admin');
        $this->assertSame('google-buyer-intended', $buyer->fresh()->google_id);
    }

    public function test_google_buyer_login_ignores_matching_admin_email(): void
    {
        $email = 'shared-admin-buyer@gmail.com';

        $buyer = Buyer::factory()->create([
            'email' => $email,
            'status' => 'approved',
            'google_id' => null,
        ]);

        Admin::forceCreate([
            'name' => 'Same Email Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->fakeGoogleUser($email, 'google-buyer-not-admin');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('buyer.home'));
        $this->assertAuthenticated('buyer');
        $this->assertGuest('admin');
        $this->assertSame('google-buyer-not-admin', $buyer->fresh()->google_id);
    }

    public function test_unknown_google_email_is_sent_back_to_login_with_an_error(): void
    {
        $this->fakeGoogleUser('nobody@gmail.com', 'google-unknown');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas('auth.status_message', GoogleAuthController::NO_ACCOUNT_MESSAGE);
        $this->assertGuest('buyer');
        // No auto-registration: the role tables are untouched.
        $this->assertDatabaseCount('buyers', 0);
    }

    public function test_google_login_refuses_an_email_that_belongs_to_two_roles(): void
    {
        Buyer::factory()->create(['email' => 'shared@gmail.com']);
        Seller::factory()->create(['email' => 'shared@gmail.com']);

        $this->fakeGoogleUser('shared@gmail.com', 'google-shared-1');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'This email is linked to more than one account type (buyer and seller). Please contact support.',
        );
        $this->assertGuest('buyer');
        $this->assertGuest('seller');
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

        $response->assertRedirect(route('home', ['login' => 1]));
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
        $googleUser = (new GoogleUser)->setRaw([
            'sub' => $id,
            'email' => $email,
            'email_verified' => true,
        ])->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google Buyer',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

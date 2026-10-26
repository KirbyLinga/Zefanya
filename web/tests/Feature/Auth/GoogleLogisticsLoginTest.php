<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google" for logistics providers, plus the role resolution the
 * role-agnostic login-modal button depends on.
 */
class GoogleLogisticsLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_google_login_links_the_account_and_signs_the_provider_in(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'email' => 'logistics@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('logistics@gmail.com', 'google-logistics-1');

        $response = $this->get(route('auth.google.role.callback', ['role' => 'logistics']));

        $response->assertRedirect(route('logistics.dashboard'));
        $this->assertAuthenticated('logistics');
        $this->assertSame('google-logistics-1', $provider->fresh()->google_id);
    }

    public function test_unknown_google_email_is_sent_back_to_login_with_an_error(): void
    {
        $this->fakeGoogleUser('nobody@gmail.com', 'google-unknown');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas('auth.status_message', GoogleAuthController::NO_ACCOUNT_MESSAGE);
        $this->assertGuest('logistics');
        $this->assertDatabaseCount('logistics_providers', 0);
    }

    public function test_provider_awaiting_approval_cannot_sign_in_with_google(): void
    {
        $provider = LogisticsProvider::factory()->pending()->create([
            'email' => 'pending-logistics@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('pending-logistics@gmail.com', 'google-logistics-2');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'Your logistics provider registration is still awaiting administrator approval.',
        );
        $this->assertGuest('logistics');
        $this->assertSame('google-logistics-2', $provider->fresh()->google_id);
    }

    public function test_role_agnostic_callback_rejects_email_linked_to_multiple_roles(): void
    {
        // One email = one role (EmailRegistry). Google must not pick a winner.
        Buyer::factory()->create(['email' => 'shared@gmail.com', 'google_id' => null]);
        LogisticsProvider::factory()->create([
            'email' => 'shared@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('shared@gmail.com', 'google-shared-1');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas('auth.status_message');
        $this->assertGuest('logistics');
        $this->assertGuest('buyer');
    }

    private function fakeGoogleUser(string $email, string $id): void
    {
        $googleUser = (new GoogleUser)->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google Provider',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

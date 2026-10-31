<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Security contract for "Continue with Google": the account is only linked or
 * signed in when Google has verified the address, emails compare
 * case-insensitively, and the stable Google id (not the mutable email) is used
 * for every subsequent sign-in.
 */
class GoogleSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unverified_google_email_is_rejected_and_not_linked(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'buyer@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('buyer@gmail.com', 'google-unverified', verified: false);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'Google has not verified this email address. Please verify it with Google and try again.',
        );
        $this->assertGuest('buyer');
        $this->assertNull($buyer->fresh()->google_id);
    }

    public function test_missing_verified_claim_is_rejected(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'buyer@gmail.com',
            'google_id' => null,
        ]);

        // No `email_verified` key at all (old/misconfigured payload).
        $googleUser = (new GoogleUser)->setRaw([
            'sub' => 'google-no-claim',
            'email' => 'buyer@gmail.com',
        ])->map([
            'id' => 'google-no-claim',
            'email' => 'buyer@gmail.com',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home', ['login' => 1]))
            ->assertSessionHas('auth.status_message');

        $this->assertGuest('buyer');
        $this->assertNull($buyer->fresh()->google_id);
    }

    public function test_email_matching_is_case_insensitive(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'Buyer@Example.COM',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('buyer@example.com', 'google-case');

        $this->get(route('auth.google.callback'))->assertRedirect(route('buyer.home'));

        $this->assertAuthenticated('buyer');
        $this->assertSame('google-case', $buyer->fresh()->google_id);
    }

    public function test_linked_google_id_matches_after_a_google_side_email_change(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'old-address@example.com',
            'google_id' => 'google-stable-id',
        ]);

        // Google now reports a different address for the same stable account id.
        $this->fakeGoogleUser('new-address@example.com', 'google-stable-id');

        $this->get(route('auth.google.callback'))->assertRedirect(route('buyer.home'));

        $this->assertAuthenticated('buyer');
        // The row is matched by google_id; its stored email is not rewritten.
        $this->assertSame('old-address@example.com', $buyer->fresh()->email);
    }

    public function test_unverified_local_account_is_not_auto_linked(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'unverified@example.com',
            'google_id' => null,
            'email_verified_at' => null,
            'status' => 'unverified',
        ]);

        $this->fakeGoogleUser('unverified@example.com', 'google-unverified-local');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'Please verify your email address before linking Google Sign-In.',
        );
        $this->assertGuest('buyer');
        $this->assertNull($buyer->fresh()->google_id);
    }

    public function test_google_account_already_linked_to_another_row_is_rejected(): void
    {
        Buyer::factory()->create([
            'email' => 'owner@example.com',
            'google_id' => 'google-taken',
        ]);

        $other = Buyer::factory()->create([
            'email' => 'other@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('other@example.com', 'google-taken');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'That Google account is already linked to another Zefanya account.',
        );
        $this->assertGuest('buyer');
        $this->assertNull($other->fresh()->google_id);
    }

    public function test_email_linked_to_a_different_google_account_is_rejected(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'buyer@gmail.com',
            'google_id' => 'google-original',
        ]);

        $this->fakeGoogleUser('buyer@gmail.com', 'google-attacker');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home', ['login' => 1]));
        $response->assertSessionHas(
            'auth.status_message',
            'This email is linked to a different Google account. Please sign in with your password.',
        );
        $this->assertGuest('buyer');
        $this->assertSame('google-original', $buyer->fresh()->google_id);
    }

    private function fakeGoogleUser(string $email, string $id, bool $verified = true): void
    {
        $googleUser = (new GoogleUser)->setRaw([
            'sub' => $id,
            'email' => $email,
            'email_verified' => $verified,
        ])->map([
            'id' => $id,
            'email' => $email,
            'name' => 'Google User',
        ]);

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}

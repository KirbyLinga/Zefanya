<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnverifiedLoginResumeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unverified_buyer_login_does_not_open_a_session_and_offers_an_otp_resume_link(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'unverified@example.com',
            'password' => 'password',
            'status' => 'unverified',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);

        $response = $this->from(route('home', ['login' => 1]))
            ->post(route('unified.login'), [
                'email' => 'unverified@example.com',
                'password' => 'password',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('auth.resume_url');
        $this->assertGuest('buyer');

        $resumeUrl = (string) $this->app['session']->get('auth.resume_url');
        $this->assertStringContainsString('/register/buyer/verify-otp/'.$buyer->id, $resumeUrl);
        // Ownership proof: the resume link must be a temporary signed URL.
        $this->assertStringContainsString('signature=', $resumeUrl);
        $this->assertStringContainsString('expires=', $resumeUrl);
    }
}

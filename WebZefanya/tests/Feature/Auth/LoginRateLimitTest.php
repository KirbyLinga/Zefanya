<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dedicated admin and seller login endpoints share the same 5-per-minute
 * throttle as the unified POST /login (routes/web.php). Five wrong attempts go
 * through; the sixth within the same minute is rejected with HTTP 429.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function assertSixthWrongAttemptIsThrottled(string $routeName): void
    {
        $payload = [
            'email' => 'nobody@example.com',
            'password' => 'not-the-right-password',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route($routeName), $payload)
                ->assertStatus(302);
        }

        $this->post(route($routeName), $payload)
            ->assertStatus(429);
    }

    public function test_the_sixth_wrong_admin_login_attempt_is_throttled(): void
    {
        $this->assertSixthWrongAttemptIsThrottled('admin.login.post');
    }

    public function test_the_sixth_wrong_seller_login_attempt_is_throttled(): void
    {
        $this->assertSixthWrongAttemptIsThrottled('seller.login.post');
    }

    public function test_a_second_email_from_the_same_ip_is_not_locked_by_the_first_email(): void
    {
        $first = [
            'email' => 'first@example.com',
            'password' => 'not-the-right-password',
        ];
        $second = [
            'email' => 'second@example.com',
            'password' => 'not-the-right-password',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('unified.login'), $first)->assertStatus(302);
        }

        $this->post(route('unified.login'), $first)->assertStatus(429);
        $this->post(route('unified.login'), $second)->assertStatus(302);
    }
}

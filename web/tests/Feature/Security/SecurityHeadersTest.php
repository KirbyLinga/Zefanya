<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_and_api_responses_send_browser_protections(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy-Report-Only');

        $this->assertStringNotContainsString(
            "trustProxies(at: '*')",
            (string) file_get_contents(base_path('bootstrap/app.php')),
        );
    }

    public function test_hsts_is_sent_only_for_a_secure_production_request(): void
    {
        $this->get(route('password.request'))->assertOk()->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('https://localhost/forgot-password')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}

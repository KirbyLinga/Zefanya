<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The app must only honour X-Forwarded-* headers from explicitly configured
 * reverse proxies. Otherwise any direct client could forge its IP (bypassing
 * IP rate limits) or force https detection.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/client-ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]));
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        config()->set('trustedproxy.proxies', []);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/client-ip');

        $response->assertOk()->assertJson([
            'ip' => '203.0.113.10',
            'secure' => false,
        ]);
    }

    public function test_forwarded_headers_are_honoured_from_a_configured_proxy(): void
    {
        config()->set('trustedproxy.proxies', '203.0.113.10');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/client-ip');

        $response->assertOk()->assertJson([
            'ip' => '198.51.100.7',
            'secure' => true,
        ]);
    }

    public function test_forwarded_headers_are_ignored_from_an_unlisted_peer(): void
    {
        config()->set('trustedproxy.proxies', '10.0.0.1');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/client-ip');

        $response->assertOk()->assertJson([
            'ip' => '203.0.113.10',
            'secure' => false,
        ]);
    }

    public function test_wildcard_proxy_configuration_is_not_used_by_the_application(): void
    {
        // Guard against a regression that reintroduces trustProxies(at: '*').
        $this->assertStringNotContainsString(
            "trustProxies(at: '*')",
            (string) file_get_contents(base_path('bootstrap/app.php')),
        );
    }

    public function test_forged_forge_host_cannot_enable_the_framework_wildcard_fallback(): void
    {
        config()->set('trustedproxy.proxies', []);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('http://evil.on-forge.com/_test/client-ip');

        $response->assertOk()->assertJson([
            'ip' => '203.0.113.10',
            'secure' => false,
        ]);
    }
}

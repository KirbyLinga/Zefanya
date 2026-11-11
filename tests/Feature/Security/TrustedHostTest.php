<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Outside local/testing the app must only answer for its own host names, so a
 * forged Host header can never end up in generated URLs (password resets,
 * signed OTP links).
 */
class TrustedHostTest extends TestCase
{
    protected function tearDown(): void
    {
        // setTrustedHosts() is static on the Symfony request; never leak it.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_configured_hosts_and_their_subdomains_are_trusted(): void
    {
        config()->set('app.trusted_hosts', 'zefanya.shop');

        $this->assertTrue($this->trusts('zefanya.shop'));
        $this->assertTrue($this->trusts('www.zefanya.shop'));
    }

    public function test_foreign_and_look_alike_hosts_are_not_trusted(): void
    {
        config()->set('app.trusted_hosts', 'zefanya.shop');

        $this->assertFalse($this->trusts('evil.example'));
        $this->assertFalse($this->trusts('zefanya.shop.evil.example'));
        $this->assertFalse($this->trusts('notzefanya.shop'));
    }

    public function test_multiple_comma_separated_hosts_are_supported(): void
    {
        config()->set('app.trusted_hosts', ' zefanya.shop , staging.example.test ,');

        $this->assertTrue($this->trusts('staging.example.test'));
        $this->assertFalse($this->trusts('other.example.test'));
    }

    public function test_a_forged_host_header_is_rejected_when_enforced(): void
    {
        config()->set('app.trusted_hosts', 'zefanya.shop');

        $request = $this->enforce(Request::create('https://evil.example/forgot-password'));

        $this->expectException(SuspiciousOperationException::class);
        $request->getHost();
    }

    public function test_a_legitimate_host_header_passes_when_enforced(): void
    {
        config()->set('app.trusted_hosts', 'zefanya.shop');

        $request = $this->enforce(Request::create('https://zefanya.shop/forgot-password'));

        $this->assertSame('zefanya.shop', $request->getHost());
    }

    /** Whether the app's TrustHosts patterns (as configured in bootstrap/app.php) accept $host. */
    private function trusts(string $host): bool
    {
        $request = $this->enforce(Request::create("https://{$host}/"));

        try {
            $request->getHost();

            return true;
        } catch (SuspiciousOperationException) {
            return false;
        }
    }

    /** Run the real TrustHosts middleware with enforcement on (the framework skips it in tests). */
    private function enforce(Request $request): Request
    {
        Request::setTrustedHosts([]);

        $middleware = new class($this->app) extends TrustHosts
        {
            protected function shouldSpecifyTrustedHosts(): bool
            {
                return true;
            }
        };

        $middleware->handle($request, fn () => response('ok'));

        return $request;
    }
}

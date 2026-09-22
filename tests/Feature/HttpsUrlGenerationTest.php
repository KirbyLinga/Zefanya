<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;
use ReflectionProperty;
use Tests\TestCase;

class HttpsUrlGenerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * In production every generated URL (routes, assets, pagination, emails)
     * must use https. AppServiceProvider::boot() force-schemes https when the
     * environment is production; bootstrap/app.php additionally trusts all
     * proxies so the reverse proxy's X-Forwarded-Proto is honored at runtime.
     *
     * The provider boots once with the testing environment, so the production
     * test flips the environment and re-runs boot() to exercise that branch,
     * then unsets the forced scheme so later tests are unaffected.
     */
    public function test_named_routes_start_with_https_in_production(): void
    {
        config()->set('app.env', 'production');

        (new AppServiceProvider($this->app))->boot();

        try {
            $this->assertTrue(
                str_starts_with(route('seller.products.index'), 'https://'),
                'Generated URLs must use https in production.'
            );
        } finally {
            URL::forceScheme(null);
            config()->set('app.env', 'testing');
        }
    }

    /**
     * The inverse check must not depend on APP_URL (a dev machine may point it
     * at https and make route() https by coincidence), so assert the generator
     * itself: outside production, boot() must leave the scheme unforced.
     */
    public function test_the_url_generator_is_not_scheme_forced_outside_production(): void
    {
        config()->set('app.env', 'testing');

        (new AppServiceProvider($this->app))->boot();

        $forced = (new ReflectionProperty(UrlGenerator::class, 'forceScheme'))
            ->getValue(app(UrlGenerator::class));

        $this->assertNull($forced);
    }
}

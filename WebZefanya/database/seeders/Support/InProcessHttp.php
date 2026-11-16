<?php

namespace Database\Seeders\Support;

use App\Http\Middleware\EnforceSingleBrowserAccount;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Sends requests through the real HTTP kernel inside a seeder, as a feature
 * test does, so seeded data passes every controller, policy and service rule.
 * Dev seeding only: CSRF, throttling and the one-account-per-browser check
 * are skipped, mail goes to the log and the session lives in memory.
 */
class InProcessHttp
{
    use MakesHttpRequests;

    private const GUARDS = ['admin', 'seller', 'logistics', 'buyer', 'rider'];

    /** @var Application */
    protected $app;

    public function __construct()
    {
        $this->app = app();
        config(['mail.default' => 'log', 'session.driver' => 'array']);
        $this->app['session']->forgetDrivers();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class, EnforceSingleBrowserAccount::class]);
    }

    /** Sign in as one account only. forgetUser() never writes to the stored user. */
    public function as(Authenticatable $user, string $guard): static
    {
        foreach (self::GUARDS as $name) {
            Auth::guard($name)->forgetUser();
        }
        Auth::guard($guard)->setUser($user);
        Auth::shouldUse($guard);

        return $this;
    }

    public function putSession(string $key, mixed $value): void
    {
        $this->app['session.store']->put($key, $value);
    }

    /**
     * Send a request and throw if it failed, was rejected by validation, or
     * flashed an error.
     *
     * @param  array<string, mixed>  $data
     * @param  array{0: Authenticatable, 1: string}|null  $as
     */
    public function send(string $method, string $url, array $data = [], ?array $as = null, bool $keepSession = false): TestResponse
    {
        if ($as !== null) {
            $this->as(...$as);
        }

        $session = $this->app['session.store'];
        if (! $keepSession) {
            $session->flush();
        }
        $session->forget(['errors', 'error']);

        $response = $method === 'postJson' ? $this->postJson($url, $data) : $this->{$method}($url, $data);

        $errors = $session->get('errors');
        $messages = is_object($errors) && method_exists($errors, 'all')
            ? $errors->all()
            : array_map('strval', Arr::flatten((array) $errors));
        $problem = match (true) {
            $response->status() >= 400 => 'HTTP '.$response->status().' '.mb_substr((string) ($response->json('message') ?? strip_tags((string) $response->getContent())), 0, 300),
            $messages !== [] => implode(' ', $messages),
            filled($session->get('error')) => (string) $session->get('error'),
            default => null,
        };

        if ($problem !== null) {
            throw new RuntimeException(parse_url($url, PHP_URL_PATH).': '.$problem);
        }

        return $response;
    }
}

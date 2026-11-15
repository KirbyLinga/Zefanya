<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * config/admin.php: admin 2FA enrolment is optional by default in every
 * environment (production included) and only required when
 * ADMIN_REQUIRE_2FA is explicitly set to a true value.
 */
class AdminTwoFactorConfigTest extends TestCase
{
    private const KEYS = ['APP_ENV', 'ADMIN_REQUIRE_2FA'];

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'putenv' => getenv($key),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $state) {
            $this->setEnv($key, $state['putenv'] === false ? null : $state['putenv']);
            if ($state['server'] !== null) {
                $_SERVER[$key] = $state['server'];
            }
            if ($state['env'] !== null) {
                $_ENV[$key] = $state['env'];
            }
        }

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?string, 2: bool}> */
    public static function cases(): array
    {
        return [
            'production, unset' => ['production', null, false],
            'production, blank' => ['production', '', false],
            'production, explicit false' => ['production', 'false', false],
            'production, explicit true' => ['production', 'true', true],
            'local, unset' => ['local', null, false],
            'testing, unset' => ['testing', null, false],
            'local, explicit true' => ['local', 'true', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_require_two_factor_is_off_unless_explicitly_enabled(string $appEnv, ?string $flag, bool $expected): void
    {
        $this->setEnv('APP_ENV', $appEnv);
        $this->setEnv('ADMIN_REQUIRE_2FA', $flag);

        $config = require dirname(__DIR__, 2).'/config/admin.php';

        $this->assertSame($expected, $config['require_two_factor']);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }
}

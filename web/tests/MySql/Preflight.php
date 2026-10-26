<?php

namespace Tests\MySql;

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

final class Preflight
{
    public static bool $passed = false;

    public const DATABASE = 'zefanya_concurrency_test';

    public const UUID = '0f44a872-9188-11f1-a27b-107c61453d72';

    public const ACCOUNT = 'zefanya_concurrency_runner@localhost';

    public static function halt(): never
    {
        fwrite(STDERR, "T05 isolation verification failed; stopping before further database work.\n");
        exit(2);
    }

    public static function configure(): void
    {
        $root = dirname(__DIR__, 2);
        if (is_file($root.'/bootstrap/cache/config.php') || getenv('APP_CONFIG_CACHE')) {
            throw new RuntimeException('T05 refuses cached or redirected configuration.');
        }
        $values = Dotenv::createArrayBacked($root.'/.codex', 't05.env')->load();
        $expected = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => self::DATABASE, 'DB_USERNAME' => 'zefanya_concurrency_runner'];
        foreach ($expected as $key => $value) {
            if (($values[$key] ?? null) !== $value) {
                throw new RuntimeException('T05 credential-file identity mismatch: '.$key);
            }
        }
        if (empty($values['DB_PASSWORD']) || ! empty($values['DB_URL']) || ! empty($values['DB_SOCKET'])) {
            throw new RuntimeException('T05 requires dedicated password authentication without URL/socket overrides.');
        }
        $safe = $expected + ['DB_PASSWORD' => $values['DB_PASSWORD'], 'DB_URL' => '', 'DB_SOCKET' => '',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
            'BCRYPT_ROUNDS' => '4', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false'];
        foreach ($safe as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public static function identity(PDO $pdo): array
    {
        $row = $pdo->query('SELECT @@server_uuid AS uuid, @@port AS port, DATABASE() AS db, CURRENT_USER() AS account')->fetch(PDO::FETCH_ASSOC);
        if ($row['uuid'] !== self::UUID || (int) $row['port'] !== 3306 || $row['db'] !== self::DATABASE || $row['account'] !== self::ACCOUNT) {
            throw new RuntimeException('T05 isolation identity failed.');
        }

        return $row;
    }

    public static function verify(PDO $pdo): array
    {
        $identity = self::identity($pdo);
        $roles = $pdo->query('SELECT CURRENT_ROLE() AS roles, @@mandatory_roles AS mandatory_roles, @@partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
        if ($roles['roles'] !== 'NONE' || $roles['mandatory_roles'] !== '' || (int) $roles['partial_revokes'] !== 0) {
            throw new RuntimeException('T05 role/grant interpretation changed.');
        }
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $usage = 'GRANT USAGE ON *.* TO `zefanya_concurrency_runner`@`localhost`';
        $privileges = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'REFERENCES', 'INDEX', 'ALTER'];
        if (count($grants) !== 2 || ! in_array($usage, $grants, true)) {
            throw new RuntimeException('T05 refuses unexpected global grants or roles.');
        }
        foreach ($grants as $grant) {
            if ($grant === $usage) {
                continue;
            }
            // SHOW GRANTS escapes the escaped underscore again in its output.
            $normalized = str_replace('\\', '', $grant);
            $suffix = ' ON `zefanya_concurrency_test`.* TO `zefanya_concurrency_runner`@`localhost`';
            if (! str_starts_with($normalized, 'GRANT ') || ! str_ends_with($normalized, $suffix)
                || ! str_contains($grant, '\\_')) {
                throw new RuntimeException('T05 refuses broad or unescaped database grants.');
            }
            $scope = explode('.* TO ', explode(' ON ', $grant, 2)[1], 2)[0];
            // Every underscore in the database scope must be escaped, not just one.
            $scope = str_replace(str_repeat(chr(92), 2), chr(92), $scope);
            if ($scope !== '`zefanya'.chr(92).'_concurrency'.chr(92).'_test`') {
                throw new RuntimeException('T05 refuses wildcard database privileges.');
            }
            $actual = explode(', ', substr($normalized, 6, -strlen($suffix)));
            sort($actual);
            sort($privileges);
            if ($actual !== $privileges) {
                throw new RuntimeException('T05 unexpected database privileges.');
            }
        }
        $denied = false;
        try {
            $pdo->exec('USE `Zefanya`');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1044) {
                throw new RuntimeException('T05 could not establish application-schema access denial.');
            }
            $denied = true;
        }
        if (! $denied) {
            throw new RuntimeException('T05 account can access the application database.');
        }
        self::identity($pdo);

        return $identity + ['application_access_denied' => true];
    }

    public static function application(): Application
    {
        self::configure();
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.codex/t05.env');
        $app->make(Kernel::class)->bootstrap();
        if (! $app->environment('testing') || config('database.default') !== 'mysql') {
            throw new RuntimeException('T05 effective Laravel configuration mismatch.');
        }
        $mysql = config('database.connections.mysql');
        if ($mysql['database'] !== self::DATABASE || $mysql['username'] !== 'zefanya_concurrency_runner'
            || $mysql['host'] !== '127.0.0.1' || ! empty($mysql['url']) || ! empty($mysql['unix_socket'])
            || ! empty($mysql['read']) || ! empty($mysql['write'])) {
            throw new RuntimeException('T05 effective connection is unsafe.');
        }
        config(['database.connections' => ['mysql' => $mysql]]);
        $connection = DB::connection('mysql');
        self::verify($connection->getPdo());
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 12');
        $connection->beforeExecuting(function ($sql, $bindings, $connection): void {
            try {
                self::identity($connection->getPdo());
            } catch (\Throwable) {
                self::halt();
            }
        });
        $connection->beforeStartingTransaction(function () use ($connection): void {
            try {
                self::identity($connection->getPdo());
            } catch (\Throwable) {
                self::halt();
            }
        });

        self::$passed = true;

        return $app;
    }

    public static function prepareSchema(): void
    {
        $pdo = DB::connection()->getPdo();
        self::verify($pdo);
        $marker = dirname(__DIR__, 2).'/.codex/t05-owned-schema.json';
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $ownership = ['database' => self::DATABASE, 'uuid' => self::UUID];
        if ($tables !== [] && (! is_file($marker) || json_decode(file_get_contents($marker), true) !== $ownership)) {
            throw new RuntimeException('T05 refuses a nonempty unowned schema.');
        }
        if ($tables === []) {
            file_put_contents($marker, json_encode($ownership), LOCK_EX);
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('T05 isolated migrations failed.');
        }
        foreach ($pdo->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN) as $engine) {
            if ($engine !== 'InnoDB') {
                throw new RuntimeException('T05 requires InnoDB tables.');
            }
        }
    }

    public static function clean(): void
    {
        $pdo = DB::connection()->getPdo();
        self::verify($pdo);
        $marker = dirname(__DIR__, 2).'/.codex/t05-owned-schema.json';
        if (! is_file($marker) || json_decode(file_get_contents($marker), true) !== ['database' => self::DATABASE, 'uuid' => self::UUID]) {
            throw new RuntimeException('T05 refuses cleanup without verified disposable-schema ownership.');
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if ($table !== 'migrations') {
                    DB::statement('TRUNCATE TABLE `'.str_replace('`', '``', $table).'`');
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}

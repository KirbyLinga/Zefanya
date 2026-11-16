<?php

namespace Tests\MySql;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationEmailConcurrencyTest extends TestCase
{
    private const MODELS = ['buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class];

    public function createApplication()
    {
        return Preflight::application();
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['logistics']];
    }

    public static function pairs(): array
    {
        $cases = [];
        foreach (array_keys(self::MODELS) as $first) {
            foreach (array_keys(self::MODELS) as $second) {
                $cases[] = [$first, $second];
            }
        }

        return $cases;
    }

    private function waitFor(string $file, array $processes): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new \RuntimeException('Registration worker exited before synchronization: '.basename($file).'; '.$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Registration synchronization deadline: '.basename($file));
            }
            usleep(10000);
        }
    }

    #[DataProvider('pairs')]
    public function test_only_one_role_can_claim_an_email_under_overlapping_mysql_registrations(string $first, string $second): void
    {
        $lock = fopen(base_path('.codex/t05-runner.lock'), 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another isolated MySQL runner owns the fixture database.');
        }
        $email = 'auth-email-race-'.bin2hex(random_bytes(12)).'@example.test';
        $processes = [];
        try {
            $directory = storage_path('logs/registration-email-races/'.bin2hex(random_bytes(8)));
            mkdir($directory, 0700, true);
            foreach (['a' => $first, 'b' => $second] as $name => $role) {
                $task = ['role' => $role, 'email' => $name === 'b' ? strtoupper($email) : $email, 'directory' => $directory, 'name' => $name];
                $file = $directory.'/'.$name.'.task.json';
                file_put_contents($file, json_encode($task, JSON_THROW_ON_ERROR));
                $processes[$name] = new Process([PHP_BINARY, __DIR__.'/registration-email-worker.php', $file], base_path());
                $processes[$name]->setTimeout(40);
                $processes[$name]->start();
            }
            foreach (['a', 'b'] as $name) {
                $this->waitFor($directory.'/'.$name.'.ready', $processes);
            }
            touch($directory.'/a.go');
            $this->waitFor($directory.'/a.held', $processes);
            touch($directory.'/b.go');
            $this->waitFor($directory.'/b.attempting', $processes);
            $connection = (int) file_get_contents($directory.'/b.connection');
            $observed = false;
            $deadline = microtime(true) + 7;
            while (! $observed && microtime(true) < $deadline) {
                foreach (DB::connection()->getPdo()->query('SHOW FULL PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if ((int) $row['Id'] === $connection && str_contains(strtolower((string) ($row['Info'] ?? '')), 'get_lock')) {
                        $observed = true;
                        break;
                    }
                }
                if (! $observed) {
                    usleep(10000);
                }
            }
            $this->assertTrue($observed, 'The second registration must wait on the same normalized-email MySQL lock.');
            touch($directory.'/a.release');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $a = json_decode(file_get_contents($directory.'/a.result.json'), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode(file_get_contents($directory.'/b.result.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($a['connection'], $b['connection']);
            $this->assertTrue($a['won']);
            $this->assertFalse($b['won']);
            $this->assertArrayHasKey('email', $b['errors']);
            $this->assertSame([$first], EmailRegistry::rolesFor($email));
            $total = 0;
            foreach (self::MODELS as $model) {
                $total += $model::whereRaw('LOWER(email) = ?', [$email])->count();
            }
            $this->assertSame(1, $total);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (self::MODELS as $model) {
                $model::whereRaw('LOWER(email) = ?', [$email])->delete();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    #[DataProvider('roles')]
    public function test_existing_mysql_email_unique_indexes_reject_case_variants(string $role): void
    {
        $email = 'auth-email-index-'.bin2hex(random_bytes(12)).'@example.test';
        $model = self::MODELS[$role];
        $attributes = ['email' => $email];
        if ($role === 'seller') {
            $attributes['line_of_business_id'] = null;
        }
        try {
            $model::factory()->create($attributes);
            try {
                $model::factory()->create(array_replace($attributes, ['email' => strtoupper($email)]));
                $this->fail('The existing MySQL unique index must reject case-insensitive duplicates.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(1, $model::whereRaw('LOWER(email) = ?', [$email])->count());
            }
        } finally {
            $model::whereRaw('LOWER(email) = ?', [$email])->delete();
        }
    }
}

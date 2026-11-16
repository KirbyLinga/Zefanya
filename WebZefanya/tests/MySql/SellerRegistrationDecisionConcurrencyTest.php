<?php

namespace Tests\MySql;

use App\Models\Admin\Admin;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationDecision;
use App\Services\Admin\SellerRegistrationDecisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SellerRegistrationDecisionConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        return Preflight::application();
    }

    public static function decisions(): array
    {
        return [['approved', 'approved'], ['approved', 'rejected'], ['rejected', 'approved'], ['rejected', 'rejected']];
    }

    private function waitFor(string $file, array $processes): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new \RuntimeException('Seller decision worker exited before synchronization: '.basename($file).'; '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Seller decision synchronization deadline: '.basename($file));
            }
            usleep(10000);
        }
    }

    #[DataProvider('decisions')]
    public function test_first_committed_seller_review_wins_over_a_blocked_stale_review(string $first, string $second): void
    {
        // Never clean the shared test database. Create and remove only these
        // explicit fixtures, after the standard restricted-MySQL preflight.
        foreach (['admins', 'sellers', 'account_action_histories'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Isolated MySQL registration prerequisite missing: '.$table);
            }
        }
        $lock = fopen(base_path('.codex/t05-runner.lock'), 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another isolated MySQL runner owns the fixture database.');
        }
        $seller = null;
        $admins = [];
        $processes = [];
        try {
            $seller = Seller::factory()->pending()->create(['line_of_business_id' => null]);
            $stale = $seller->fresh();
            $admins = [Admin::factory()->create(), Admin::factory()->create()];
            $directory = storage_path('logs/seller-registration-races/'.bin2hex(random_bytes(8)));
            mkdir($directory, 0700, true);
            foreach (['a' => $first, 'b' => $second] as $name => $decision) {
                $task = ['seller_id' => $seller->id, 'admin_id' => $admins[$name === 'a' ? 0 : 1]->id, 'decision' => $decision, 'name' => $name, 'directory' => $directory];
                $taskFile = $directory.'/'.$name.'.task.json';
                file_put_contents($taskFile, json_encode($task, JSON_THROW_ON_ERROR));
                $processes[$name] = new Process([PHP_BINARY, __DIR__.'/seller-registration-worker.php', $taskFile], base_path());
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
            while (microtime(true) < $deadline && ! $observed) {
                foreach (DB::connection()->getPdo()->query('SHOW FULL PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $sql = strtolower((string) ($row['Info'] ?? ''));
                    if ((int) $row['Id'] === $connection && str_contains($sql, 'sellers') && str_contains($sql, 'for update')) {
                        $observed = true;
                        break;
                    }
                }
                if (! $observed) {
                    usleep(10000);
                }
            }
            $this->assertTrue($observed, 'The competing MySQL Seller locking read must overlap the first transaction.');
            touch($directory.'/a.release');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $a = json_decode(file_get_contents($directory.'/a.result.json'), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode(file_get_contents($directory.'/b.result.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('pending', $a['loaded_status']);
            $this->assertSame('pending', $b['loaded_status']);
            $this->assertNotSame($a['connection'], $b['connection']);
            $this->assertTrue($a['won']);
            $this->assertFalse($b['won']);
            $this->assertSame(1, $a['notifications']);
            $this->assertSame(0, $b['notifications']);
            $current = $seller->fresh();
            $this->assertSame($first, $current->status);
            $this->assertSame($admins[0]->id, $current->approved_by);
            $this->assertNotNull($current->approved_at);
            $this->assertSame($first === 'rejected' ? 'Incomplete permit' : null, $current->rejection_reason);
            $history = DB::table('account_action_histories')->where('account_type', 'seller')->where('account_id', $seller->id);
            $this->assertSame(1, $history->count());
            $this->assertSame($admins[0]->id, (int) $history->value('admin_id'));
            $this->assertSame($first, $history->value('action'));
            Notification::fake([SellerRegistrationDecision::class]);
            $this->assertFalse(app(SellerRegistrationDecisionService::class)->decide($stale, $admins[1], $second, 'Another reason'));
            $this->assertSame(1, $history->count());
            Notification::assertNothingSent();
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            if ($seller !== null) {
                DB::table('account_action_histories')->where('account_type', 'seller')->where('account_id', $seller->id)->delete();
                DB::table('sellers')->where('id', $seller->id)->delete();
            }
            foreach ($admins as $admin) {
                DB::table('admins')->where('id', $admin->id)->delete();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

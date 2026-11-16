<?php

namespace Tests\Feature\Console;

use App\Models\Admin\Admin;
use App\Notifications\Admin\ScheduledTaskFailed;
use App\Support\ScheduledTaskAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScheduledTaskAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_failed_task_is_recorded_and_emailed_to_admins(): void
    {
        Notification::fake();
        $admin = Admin::factory()->create(['role' => 'admin']);
        Admin::factory()->create(['role' => 'moderator']);

        app(ScheduledTaskAlert::class)->failed('orders:auto-complete-receipts');

        Notification::assertSentTo($admin, ScheduledTaskFailed::class);
        Notification::assertNotSentTo(Admin::query()->where('role', 'moderator')->first(), ScheduledTaskFailed::class);
        $this->assertNotNull(Cache::get('scheduler:last_failure:orders:auto-complete-receipts'));
    }

    public function test_settlement_tasks_token_pruning_and_failed_job_pruning_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->implode("\n");

        $this->assertStringContainsString('orders:auto-complete-receipts', $commands);
        $this->assertStringContainsString('returns:auto-approve-overdue', $commands);
        $this->assertStringContainsString('pickups:roll-missed-windows', $commands);
        $this->assertStringContainsString('sanctum:prune-expired', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }
}

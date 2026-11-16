<?php

namespace App\Support;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Notifications\Admin\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ScheduledTaskAlert
{
    public function apply(Event $event, string $task): void
    {
        $event->onSuccess(function () use ($task): void {
            $this->succeeded($task);
        })->onFailure(function () use ($task): void {
            $this->failed($task);
        });
    }

    public function succeeded(string $task): void
    {
        Cache::put('scheduler:last_success:'.$task, now()->toIso8601String(), now()->addDays(14));
    }

    public function failed(string $task): void
    {
        Log::critical('Scheduled task failed.', ['task' => $task]);
        Cache::put('scheduler:last_failure:'.$task, now()->toIso8601String(), now()->addDays(14));

        $admins = Admin::query()
            ->whereIn('role', [AdminRole::SuperAdmin->value, AdminRole::Admin->value])
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ScheduledTaskFailed($task));
        }
    }
}

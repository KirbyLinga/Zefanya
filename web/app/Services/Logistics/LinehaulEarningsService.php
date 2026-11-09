<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Policies\LinehaulPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinehaulEarningsService
{
    public function report(LinehaulDriver $driver, array $data): array
    {
        abort_unless((new LinehaulPolicy)->access($driver), 403);
        $today = now('Asia/Manila')->toDateString();
        $from = Carbon::createFromFormat('!Y-m-d', $data['from'] ?? $today, 'Asia/Manila');
        $to = Carbon::createFromFormat('!Y-m-d', $data['to'] ?? $today, 'Asia/Manila');
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose an ordered range of at most 366 days.']);
        }
        $start = $from->copy()->setTimezone(config('app.timezone'));
        $end = $to->copy()->addDay()->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($driver, $data, $from, $to, $start, $end): array {
            $query = LinehaulAssignment::currentOffer()->where('linehaul_driver_id', $driver->id)
                ->whereHas('manifest', fn ($q) => $q->where('logistics_provider_id', $driver->logistics_provider_id)
                    ->whereIn('status', [ManifestStatus::Received->value, ManifestStatus::ReceivedWithExceptions->value])
                    ->where('received_at', '>=', $start)->where('received_at', '<', $end))
                ->with(['manifest', 'driver'])
                ->when(LinehaulTransferService::installed(), fn ($query) => $query->with('latestTransfer'));
            $payoutsInstalled = LinehaulPayoutService::installed();
            $total = 0; $count = 0; $earned = 0;
            (clone $query)->chunkById(100, function ($assignments) use (&$total, &$count, &$earned): void {
                foreach ($assignments as $assignment) {
                    $count++;
                    $pay = LinehaulPayService::details($assignment);
                    if ($pay['status'] === 'earned') {
                        $total += $pay['amount_minor']; $earned++;
                    }
                }
            });
            $page = (int) ($data['page'] ?? 1);
            $rows = (clone $query)->when($payoutsInstalled, fn ($query) => $query->with('payout'))
                ->orderByDesc('id')->forPage($page, 20)->get()->map(function ($assignment) use ($payoutsInstalled, $driver): array {
                $payout = $payoutsInstalled ? $assignment->payout : null;
                $paidOn = $payout !== null && (int) $payout->linehaul_driver_id === (int) $driver->id
                    && (int) $payout->logistics_provider_id === (int) $driver->logistics_provider_id
                    ? $payout->paid_on->toDateString() : null;
                return ['trip_id' => $assignment->manifest_id, 'completed_at' => $assignment->manifest->received_at->toIso8601String(),
                    'result' => $assignment->manifest->status === ManifestStatus::Received ? 'Completed' : 'Received with Issues',
                    'trip_pay' => LinehaulPayService::details($assignment),
                    ...($payoutsInstalled ? ['paid_on' => $paidOn] : [])];
            })->all();

            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'Asia/Manila',
                'earned_minor' => $total, 'earned_trips' => $earned, 'unconfirmed_trips' => $count - $earned,
                'completed_trips' => $count, 'page' => $page, 'last_page' => max(1, (int) ceil($count / 20)), 'trips' => $rows,
                ...($payoutsInstalled ? ['payout_recording_available' => true] : [])];
        });
    }
}

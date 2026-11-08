<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Http\Resources\Mobile\LinehaulDeclineResource;
use App\Http\Resources\Mobile\LinehaulTripResource;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulTripDecline;
use App\Policies\LinehaulPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinehaulHistoryService
{
    public function report(LinehaulDriver $driver, array $data, Request $request): array
    {
        abort_unless((new LinehaulPolicy)->access($driver), 403);
        $today = now('Asia/Manila')->toDateString();
        $from = Carbon::createFromFormat('!Y-m-d', $data['from'] ?? $today, 'Asia/Manila');
        $to = Carbon::createFromFormat('!Y-m-d', $data['to'] ?? $today, 'Asia/Manila');
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose an ordered range of at most 366 days.']);
        }

        return DB::transaction(function () use ($driver, $data, $request, $from, $to): array {
            $trips = DB::table('linehaul_assignments as a')->join('manifests as m', 'm.id', '=', 'a.manifest_id')
                ->where('a.linehaul_driver_id', $driver->id)->where('m.logistics_provider_id', $driver->logistics_provider_id)
                ->whereIn('m.status', [ManifestStatus::Received->value, ManifestStatus::ReceivedWithExceptions->value, ManifestStatus::Cancelled->value])
                ->selectRaw("'trip' as kind, a.id as source_id, COALESCE(m.received_at, m.cancelled_at, m.updated_at) as recorded_at");
            if (LinehaulDeclineService::installed()) {
                $trips->whereNull('a.declined_at');
                $declines = DB::table('linehaul_trip_declines')->where('linehaul_driver_id', $driver->id)
                    ->where('logistics_provider_id', $driver->logistics_provider_id)
                    ->selectRaw("'decline' as kind, id as source_id, created_at as recorded_at");
                $trips->unionAll($declines);
            }
            $page = DB::query()->fromSub($trips, 'history')
                ->where('recorded_at', '>=', $from->copy()->setTimezone(config('app.timezone')))
                ->where('recorded_at', '<', $to->copy()->addDay()->setTimezone(config('app.timezone')))
                ->orderByDesc('recorded_at')->orderBy('kind')->orderByDesc('source_id')
                ->paginate(20, ['*'], 'page', (int) ($data['page'] ?? 1));
            $records = collect($page->items());
            $assignments = LinehaulAssignment::currentOffer()->whereIn('id', $records->where('kind', 'trip')->pluck('source_id'))
                ->where('linehaul_driver_id', $driver->id)
                ->whereHas('manifest', fn ($q) => $q->where('logistics_provider_id', $driver->logistics_provider_id))
                ->with(['driver', 'manifest.originHub', 'manifest.destinationHub', 'manifest.items.sellerOrder:id,parcel_weight_grams'])
                ->when(LinehaulDelayService::installed(), fn ($q) => $q->with('latestDelay'))->get()->keyBy('id');
            $declines = LinehaulDeclineService::installed() ? LinehaulTripDecline::whereIn('id', $records->where('kind', 'decline')->pluck('source_id'))
                ->where('linehaul_driver_id', $driver->id)->where('logistics_provider_id', $driver->logistics_provider_id)->get()->keyBy('id') : collect();
            $rows = $records->map(function ($record) use ($assignments, $declines, $request): ?array {
                $model = $record->kind === 'trip' ? $assignments->get($record->source_id) : $declines->get($record->source_id);
                if ($model === null) return null;
                $row = $record->kind === 'trip' ? (new LinehaulTripResource($model))->resolve($request) : (new LinehaulDeclineResource($model))->resolve($request);

                return [...$row, 'history_key' => $record->kind.':'.$record->source_id,
                    'recorded_at' => Carbon::parse($record->recorded_at, config('app.timezone'))->toIso8601String()];
            })->filter()->values()->all();

            return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'Asia/Manila',
                'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'trips' => $rows];
        });
    }
}

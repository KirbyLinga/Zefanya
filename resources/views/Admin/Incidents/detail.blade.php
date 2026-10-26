@extends('Admin.Layouts.app')

@section('title', 'Incident #'.$incident->id)

@section('content')
    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-start gap-6">
        <div class="flex-1 min-w-[320px]">
            <h1 class="text-[28px] font-bold text-neutral-950 mb-1">Incident #{{ $incident->id }}</h1>
            <p class="text-base text-neutral-600 mb-4">Parcel #{{ $incident->incident_seller_order_id }} ({{ $incident->type ?? '—' }})</p>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-base">
                <div class="bg-white border border-neutral-200 rounded-lg p-4">
                    <dt class="text-xs font-semibold text-neutral-500 uppercase tracking-[0.5px]">Type</dt>
                    <dd class="mt-1 font-semibold text-neutral-950">{{ $incident->type ?? '—' }}</dd>
                </div>
                <div class="bg-white border border-neutral-200 rounded-lg p-4">
                    <dt class="text-xs font-semibold text-neutral-500 uppercase tracking-[0.5px]">Reporter</dt>
                    <dd class="mt-1 font-semibold text-neutral-950">{{ $incident->reporter_type ?? '—' }} #{{ $incident->reporter_id ?? '—' }}</dd>
                </div>
                <div class="bg-white border border-neutral-200 rounded-lg p-4">
                    <dt class="text-xs font-semibold text-neutral-500 uppercase tracking-[0.5px]">Status</dt>
                    <dd class="mt-1">
                        @if ($incident->isOpen())
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">OPEN</span>
                        @else
                            <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">RESOLVED</span>
                        @endif
                    </dd>
                </div>
                <div class="bg-white border border-neutral-200 rounded-lg p-4">
                    <dt class="text-xs font-semibold text-neutral-500 uppercase tracking-[0.5px]">Description</dt>
                    <dd class="mt-1 text-neutral-700">{{ $incident->description ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="w-full sm:max-w-lg">
            @can('resolve', $incident)
                <form method="POST" action="{{ route('admin.incidents.resolve', $incident) }}">
                    @csrf
                    <div class="mb-3">
                        <label for="resolution" class="block text-xs font-semibold text-neutral-600 mb-2">Resolution action</label>
                        <select id="resolution" name="resolution" required class="w-full border border-neutral-300 rounded-lg p-3 text-sm">
                            <option value="">Select an action</option>
                            <option value="RESUME" @selected(old('resolution') === 'RESUME')>RESUME ? allow normal parcel operations</option>
                            <option value="RETURN" @selected(old('resolution') === 'RETURN')>RETURN ? authorize the existing return workflow</option>
                            <option value="CLOSE_LOST" @selected(old('resolution') === 'CLOSE_LOST') @disabled(strtolower($incident->type) !== 'lost')>CLOSE_LOST ? permanently close a lost parcel</option>
                            <option value="CLOSE_DAMAGED" @selected(old('resolution') === 'CLOSE_DAMAGED') @disabled(strtolower($incident->type) !== 'damaged')>CLOSE_DAMAGED ? permanently close a damaged parcel</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="resolution_note" class="block text-xs font-semibold text-neutral-600 mb-2">Resolution reason</label>
                        <textarea id="resolution_note" name="resolution_note" rows="3" required maxlength="1000" class="w-full border border-neutral-300 rounded-lg p-3 text-sm">{{ old('resolution_note') }}</textarea>
                    </div>
                    <p class="mb-3 text-xs text-neutral-500">RETURN requires an eligible failed delivery. Permanent closure keeps parcel operations blocked and cannot be resumed here.</p>
                    <button type="submit" class="h-10 px-4 bg-primary-800 text-white font-semibold text-[13px] rounded-lg hover:bg-primary-900 transition-colors" onclick="return confirm('Record this resolution? Permanent closures cannot be undone here.')">Record resolution</button>
                </form>
            @else
                <p class="text-sm text-neutral-600">This incident has been resolved. Its audit history is preserved below.</p>
            @endcan
        </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg p-5">
        <h2 class="text-lg font-semibold text-neutral-950 mb-4">Event history ({{ $incident->events->count() }})</h2>
        @if ($incident->events->count() > 0)
            <div class="space-y-3">
                @foreach ($incident->events->reverse() as $event)
                    <div class="flex items-start gap-4 p-3 rounded-lg border border-neutral-200 bg-neutral-50">
                        <div class="shrink-0">
                            <i data-lucide="clock" class="w-5 h-5 text-neutral-500"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-neutral-950">{{ $event->label ?? $event->event }}</p>
                            @if ($event->resolved_by_admin_id)
                                <p class="text-xs text-neutral-600">Resolved by Admin #{{ $event->resolved_by_admin_id }}</p>
                            @endif
                            @if ($event->resolution_reason)
                                <p class="text-sm text-neutral-700">{{ $event->resolution_reason }}</p>
                            @endif
                            <p class="text-xs text-neutral-500">
                                {{ $event->occurred_at ? $event->occurred_at->setTimezone('UTC')->format('Y-m-d\TH:i:s.vP') : '—' }}
                                @if ($event->detail)
                                    <span class="block text-xs text-neutral-400">{{ $event->detail }}</span>
                                @endif
                                @if (isset($eventCounts[$event->event]))
                                    <p class="text-xs text-neutral-400">Total {{ $event->event }} events: {{ $eventCounts[$event->event] }}</p>
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-neutral-500">No events recorded for this incident.</p>
        @endif
    </div>
@endsection

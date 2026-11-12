@extends('Layouts.logistics')

@section('title', 'Exception #'.$incident->id)

@section('content')
@php
    $statusValue = $incident->status?->value ?? $incident->status;
    $typeValue = strtolower((string) $incident->type);
    $routingTypes = ['missing', 'wrong_hub', 'inactive_connection', 'failed_transfer'];
    $actionable = $statusValue !== 'resolved' || $incident->isHeld();
    $canReroute = $canResolve && in_array($typeValue, $routingTypes, true) && $actionable;
    $legs = is_array($route?->legs) ? $route->legs : ($route?->legs ? json_decode($route->legs, true) : []);
    $workerReceipt = session('worker_receipt_token');
    $workerRecoveryReceived = $incident->events->contains('event', 'worker_station_received');
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">Exception #{{ $incident->id }}</h1>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Parcel #{{ $incident->incident_seller_order_id }} · {{ $incident->typeLabel() }}</p>
    </div>
    <a href="{{ route('logistics.exceptions.index') }}" class="text-sm font-semibold text-primary-700 hover:text-primary-900">Back to exceptions</a>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />
<div role="status" class="mb-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 text-sm text-[var(--text-ink)]">
    {{ $incident->isActive() || $incident->isHeld() ? 'On hold - parcel movement is blocked.' : 'Exception resolved. Other active exceptions may still block movement.' }}
    @if (in_array($typeValue, ['lost', 'damaged'], true)) Admin review is required for this exception. @endif
    @if ($typeValue === 'wrong_hub' && $statusValue === 'under_review')
        <p class="mt-2">Use the existing manifest or handoff receiving scan to record the physical recovery. The parcel stays held until an authorized route decision.</p>
    @endif
</div>

@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if ($typeValue === 'worker_unavailable' && $canResolve && $actionable)
    <section class="mb-4 space-y-3 rounded-lg border p-4 text-sm">
        <h2 class="font-semibold">Worker custody recovery</h2>
        <p>Recorded custodian: {{ $incident->custody_snapshot['worker_kind'] ?? 'Worker' }} #{{ $incident->custody_snapshot['worker_id'] ?? '—' }}. Recovery owner: {{ $incident->events->firstWhere('event', 'recovery_owner')?->detail ?? '—' }}.</p>
        <form method="POST" action="{{ route('logistics.exceptions.worker-recovery', $incident) }}" class="grid gap-2">
            @csrf
            <label>Contact attempt or escalation reason <textarea name="note" required maxlength="1000" class="block w-full rounded border p-2"></textarea></label>
            <div class="flex flex-wrap gap-2">
                <button name="action" value="contact" class="min-h-10 rounded border px-4">Log contact attempt</button>
                <button name="action" value="escalate" class="min-h-10 rounded border px-4">Escalate to Admin</button>
            </div>
        </form>
        @if (($incident->custody_snapshot['worker_kind'] ?? null) === 'rider')
            @if ($workerRecoveryReceived)
                <form method="POST" action="{{ route('logistics.exceptions.worker-recovery', $incident) }}">
                    @csrf
                    <p class="mb-2">Physical station receipt is recorded. An in-transit delivery still needs an eligible replacement Rider before the case can close.</p>
                    <button name="action" value="finish" class="min-h-10 rounded bg-primary-800 px-4 text-white">Finish verified recovery</button>
                </form>
            @elseif (($workerReceipt['case'] ?? null) === $incident->id)
                <form method="POST" action="{{ route('logistics.exceptions.worker-recovery', $incident) }}" class="grid gap-2">
                    @csrf
                    <input type="hidden" name="verification_token" value="{{ $workerReceipt['token'] }}">
                    <label><input type="checkbox" name="physically_received" value="1" required> I physically received this parcel at {{ $workerReceipt['station'] }}.</label>
                    <button name="action" value="receive" class="min-h-10 rounded bg-primary-800 px-4 text-white">Record station receipt</button>
                </form>
            @else
                <form method="POST" action="{{ route('logistics.exceptions.worker-recovery', $incident) }}" class="grid gap-2">
                    @csrf
                    <label>Tracking number on the recovered parcel <input name="tracking_number" required maxlength="100" class="block rounded border p-2"></label>
                    <button name="action" value="verify" class="min-h-10 rounded border px-4">Verify parcel for station receipt</button>
                </form>
            @endif
        @else
            <p>Move this case under review, then use the existing manifest receiving scan for each parcel physically recovered at the authorized hub.</p>
            @if (isset($incident->custody_snapshot['manifest_id']))
                <p><a class="font-semibold underline" href="{{ route('logistics.manifests.receive.index', $incident->custody_snapshot['manifest_id']) }}">Open manifest #{{ $incident->custody_snapshot['manifest_id'] }} receiving</a></p>
            @endif
        @endif
    </section>
@endif

<div class="grid gap-4 lg:grid-cols-3">
    <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 lg:col-span-2">
        <h2 class="text-base font-semibold text-[var(--text-ink)]">Exception</h2>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div><dt class="text-xs text-[var(--text-muted)]">Status</dt><dd class="font-semibold">{{ strtoupper(str_replace('_', ' ', $statusValue)) }}</dd></div>
            <div><dt class="text-xs text-[var(--text-muted)]">Type</dt><dd class="font-semibold">{{ $incident->typeLabel() }}</dd></div>
            <div><dt class="text-xs text-[var(--text-muted)]">Reporter</dt><dd>{{ $incident->reporter_type }} #{{ $incident->reporter_id ?? '—' }}</dd></div>
            <div><dt class="text-xs text-[var(--text-muted)]">Reported</dt><dd>{{ $incident->created_at?->format('M d, Y H:i') ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-[var(--text-muted)]">Reason</dt><dd>{{ $incident->reason ?? '—' }}</dd></div>
            @if ($incident->description)
                <div class="sm:col-span-2"><dt class="text-xs text-[var(--text-muted)]">Notes</dt><dd>{{ $incident->description }}</dd></div>
            @endif
            @if ($incident->resolution)
                <div><dt class="text-xs text-[var(--text-muted)]">Resolution</dt><dd class="font-semibold">{{ $incident->resolution }}</dd></div>
                <div><dt class="text-xs text-[var(--text-muted)]">Resolved</dt><dd>{{ $incident->resolved_at?->format('M d, Y H:i') ?? '—' }}</dd></div>
            @endif
        </dl>
    </section>

    <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
        <h2 class="text-base font-semibold text-[var(--text-ink)]">Physical custody</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Last verified hub</dt><dd class="font-semibold text-right">{{ $lastVerifiedHub?->name ?? 'No verified receipt' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Custody provider</dt><dd class="text-right">{{ $sellerOrder->currentCustodyProviderId() ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Custody hub</dt><dd class="text-right">{{ $sellerOrder->custody_hub_id ?? '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Blocked leg</dt><dd class="text-right">{{ $incident->blocked_leg_index ?? '—' }}</dd></div>
        </dl>
    </section>
</div>

@if ($route)
<section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-base font-semibold text-[var(--text-ink)]">Saved multi-leg route</h2>
    <p class="mt-1 text-sm text-[var(--text-muted)]">
        Origin: {{ $route->originHub?->name ?? '—' }} → Destination: {{ $route->destinationHub?->name ?? '—' }}
        · current leg index {{ $route->current_leg_index }} of {{ count($legs) }}
        @if ($route->isCompleted()) · completed {{ $route->route_completed_at?->format('M d, Y H:i') }} @endif
    </p>
    <ol class="mt-3 space-y-2 text-sm">
        @forelse ($legs as $index => $leg)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-[var(--border-strong)] px-3 py-2 {{ $index === (int) $route->current_leg_index ? 'bg-amber-50' : '' }}">
                <span>Leg {{ $index }}: hub {{ $leg['hub_id'] ?? '—' }} via {{ $leg['transport_mode'] ?? '—' }}</span>
                <span class="text-xs text-[var(--text-muted)]">{{ $route->isCompleted() || $index < (int) $route->current_leg_index ? 'Completed' : ($index === (int) $route->current_leg_index ? 'Current / blocked' : 'Future') }}</span>
            </li>
        @empty
            <li class="text-[var(--text-muted)]">Direct same-hub route; no transfer legs.</li>
        @endforelse
    </ol>
</section>
@endif

@if ($actionable && $canResolve)
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    @if ($statusValue === 'open' || $incident->reviewed_at === null)
    <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
        <h2 class="text-base font-semibold text-[var(--text-ink)]">Review exception</h2>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Review records the decision maker. The parcel remains blocked until a resolution is confirmed.</p>
        <form method="POST" action="{{ route('logistics.exceptions.review', $incident) }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label for="review_note" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Review note</label>
                <textarea id="review_note" name="review_note" rows="3" maxlength="1000" required class="w-full rounded-lg border border-[var(--border-strong)] px-3 py-2 text-sm"></textarea>
            </div>
            <button type="submit" class="min-h-10 rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900">Move under review</button>
        </form>
    </section>
    @endif

    <section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
        <h2 class="text-base font-semibold text-[var(--text-ink)]">Resolve exception</h2>
        <p class="mt-1 text-sm text-[var(--text-muted)]">Resolution never creates a scan, custody transfer, delivery, refund or restock. It only authorizes the next safe movement.</p>
        <form method="POST" action="{{ route('logistics.exceptions.resolve', $incident) }}" class="mt-3 space-y-3">
            @csrf
            <div>
                <label for="resolution" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Resolution</label>
                <select id="resolution" name="resolution" required class="min-h-10 w-full rounded-lg border border-[var(--border-strong)] px-3 text-sm">
                    <option value="">Select a resolution</option>
                    @if ($typeValue !== 'worker_unavailable' || $incident->events->contains('event', 'recovery_received'))
                        <option value="{{ \App\Services\Logistics\ParcelIncidentService::RESUME }}">Resume original route</option>
                    @endif
                    @if (in_array($typeValue, $routingTypes, true))
                        <option value="{{ \App\Services\Logistics\ParcelIncidentService::REROUTE }}">Authorize reroute</option>
                    @endif
                    <option value="{{ \App\Services\Logistics\ParcelIncidentService::HOLD }}">Keep on hold</option>
                </select>
            </div>
            <div>
                <label for="resolution_note" class="mb-1 block text-xs font-semibold text-[var(--text-muted)]">Resolution reason *</label>
                <textarea id="resolution_note" name="resolution_note" rows="3" maxlength="1000" required class="w-full rounded-lg border border-[var(--border-strong)] px-3 py-2 text-sm"></textarea>
            </div>
            <button type="submit" class="min-h-10 rounded-lg bg-primary-800 px-4 text-sm font-semibold text-white hover:bg-primary-900" onclick="return confirm('Record this resolution? It will not fabricate a scan, custody transfer or delivery.')">Confirm resolution</button>
        </form>
    </section>
</div>
@endif

@if ($canReroute)
<section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-base font-semibold text-[var(--text-ink)]">Alternative route</h2>
    <p class="mt-1 text-sm text-[var(--text-muted)]">Preview a route from the last verified hub to the original destination using active transport connections and directional provider links.</p>

    <form method="POST" action="{{ route('logistics.exceptions.preview', $incident) }}" class="mt-3">
        @csrf
        <button type="submit" class="min-h-10 rounded-lg border border-primary-700 px-4 text-sm font-semibold text-primary-800 hover:bg-primary-50">Preview valid alternative</button>
    </form>

    @if ($reroutePreview)
        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-semibold">{{ $reroutePreview['description'] }}</p>
            <p class="mt-1">From verified hub {{ $reroutePreview['verified_hub'] }} to {{ $reroutePreview['destination'] }}.</p>
            <ol class="mt-2 list-decimal pl-5">
                @foreach ($reroutePreview['new_path'] as $leg)
                    <li>Hub {{ $leg['hub_id'] ?? '—' }} via {{ $leg['transport_mode'] ?? '—' }}</li>
                @endforeach
            </ol>
            <form method="POST" action="{{ route('logistics.exceptions.resolve', $incident) }}" class="mt-3">
                @csrf
                <input type="hidden" name="resolution" value="{{ \App\Services\Logistics\ParcelIncidentService::REROUTE }}">
                <label for="reroute_reason" class="block font-semibold">Reroute reason</label>
                <textarea id="reroute_reason" name="resolution_note" required maxlength="1000" rows="3" class="mt-2 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-3 text-[var(--text-ink)]"></textarea>
                <button type="submit" class="min-h-10 rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800" onclick="return confirm('Authorize this reroute? Completed legs and custody history are preserved.')">Confirm authorized reroute</button>
            </form>
        </div>
    @endif
</section>
@endif

<section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-base font-semibold text-[var(--text-ink)]">Custody history</h2>
    <ol class="mt-3 flex flex-col gap-3 text-sm text-[var(--text-ink)]">
        @forelse ($custodyHistory as $movement)
            <li class="rounded-lg border border-[var(--border-strong)] p-3">
                <p class="font-semibold">{{ $movement['title'] }}</p>
                <p class="text-xs text-[var(--text-muted)]">{{ $movement['at']?->format('M d, Y H:i') }}</p>
                @if (! empty($movement['custody']))<p>{{ $movement['custody'] }}</p>@endif
            </li>
        @empty
            <li>No physical custody events recorded.</li>
        @endforelse
    </ol>
</section>

<section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-base font-semibold text-[var(--text-ink)]">Audit history ({{ $incident->events->count() }})</h2>
    <div class="mt-3 space-y-3">
        @forelse ($incident->events->reverse() as $event)
            <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-page)] p-3 text-sm">
                <p class="font-semibold text-[var(--text-ink)]">{{ $event->label ?? $event->event }}</p>
                <p class="text-xs text-[var(--text-muted)]">
                    {{ $event->occurred_at?->format('M d, Y H:i') ?? '—' }}
                    @if ($event->actor_type) · {{ $event->actor_type }} #{{ $event->actor_id ?? '—' }} @endif
                    @if ($event->from_status || $event->to_status) · {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }} @endif
                </p>
                @if ($event->detail)<p class="mt-1 text-xs text-[var(--text-muted)]">{{ $event->metadata['note'] ?? $event->detail }}</p>@endif
            </div>
        @empty
            <p class="text-sm text-[var(--text-muted)]">No events recorded.</p>
        @endforelse
    </div>
</section>

@if ($rereoutes->isNotEmpty())
<section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <h2 class="text-base font-semibold text-[var(--text-ink)]">Reroute audit</h2>
    <div class="mt-3 space-y-3 text-sm">
        @foreach ($rereoutes as $rereoute)
            <div class="rounded-lg border border-[var(--border-strong)] p-3">
                <p class="font-semibold">{{ $rereoute->rerouted_at?->format('M d, Y H:i') ?? $rereoute->created_at?->format('M d, Y H:i') }} · {{ $rereoute->reason }}</p>
                <p class="text-xs text-[var(--text-muted)]">Authorized by {{ $rereoute->authorized_by_type ?? '—' }} #{{ $rereoute->authorized_by_id ?? '—' }}</p>
                <p class="mt-1 text-xs">Previous path: {{ count($rereoute->previous_path ?? []) }} leg(s) · New path: {{ count($rereoute->new_path ?? []) }} leg(s)</p>
            </div>
        @endforeach
    </div>
</section>
@endif
@endsection

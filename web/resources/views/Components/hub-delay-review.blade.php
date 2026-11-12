@php
    $snapshot = $incident->custody_snapshot;
    $review = $incident->resolution_context ?? [];
    $level = (int) $snapshot['level'];
    $waitingSince = \Illuminate\Support\Carbon::parse($snapshot['waiting_since']);
    $deadline = $waitingSince->copy()->addHours($level < 120 ? 120 : $level)->timezone(config('app.schedule_timezone'));
    $nextAction = match ($level) {
        24 => 'Check what is preventing the parcel from leaving the hub.',
        48 => 'Assign a hub lead to confirm the next available movement.',
        120 => 'Review route availability, alternate connections, staffing, and cost.',
        168 => 'Assess cancellation, refund, compensation, or possible loss through the authorized workflows.',
    };
@endphp

<section class="mb-4 space-y-3 rounded-lg border border-neutral-200 bg-white p-4 text-sm">
    <h2 class="font-semibold">{{ \App\Services\Logistics\HubDelayService::LEVELS[$level] }}</h2>
    <dl class="grid gap-2 sm:grid-cols-2">
        <div><dt class="font-semibold">Verified hub</dt><dd>{{ $snapshot['hub_name'] }} · Provider #{{ $snapshot['provider_id'] }}</dd></div>
        <div><dt class="font-semibold">Waiting time</dt><dd>{{ $waitingSince->diffForHumans($incident->resolved_at ?? now(), true) }}</dd></div>
        <div><dt class="font-semibold">Next expected movement</dt><dd>{{ $snapshot['next_hub'] ?? 'Review the next dispatch or delivery assignment' }}</dd></div>
        <div><dt class="font-semibold">Delay reason</dt><dd>{{ $snapshot['delay_reason'] ?? 'Under investigation' }}</dd></div>
        <div><dt class="font-semibold">Action owner</dt><dd>{{ $review['owner'] ?? 'Responsible hub team' }}</dd></div>
        <div><dt class="font-semibold">Review deadline</dt><dd>{{ $deadline->format('M j, Y H:i T') }}</dd></div>
    </dl>
    @if ($incident->isActive())
        <p>{{ $nextAction }}</p>
        @if ($level >= 120 && ($review['level'] ?? 0) < $level)
            <p class="font-semibold text-amber-800">Review required</p>
        @endif
    @endif
    @if ($action && $incident->isActive())
        <form method="POST" action="{{ $action }}" class="grid gap-3">
            @csrf
            <label>Action owner <input name="action_owner" required maxlength="120" value="{{ old('action_owner', $review['owner'] ?? '') }}" class="block w-full rounded border border-neutral-300 p-2"></label>
            <label>Decision and next action <textarea name="review_note" required maxlength="1000" class="block w-full rounded border border-neutral-300 p-2">{{ old('review_note') }}</textarea></label>
            <button type="submit" class="min-h-10 justify-self-start rounded-lg bg-primary-800 px-4 font-semibold text-white">Record review</button>
        </form>
    @endif
</section>

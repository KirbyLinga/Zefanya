@extends('Layouts.logistics')
@section('title', 'Courier vehicle records')
@section('content')
<section class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 text-[var(--text-ink)]">
    <h1 class="mb-4 text-xl font-semibold">{{ $driver->name }} · Vehicle & documents</h1>
    <p class="mb-4">Company: {{ $driver->provider->business_name }} · Home hub: {{ $driver->homeHub->name }}</p>
    @foreach ($errors->all() as $error)<p role="alert" class="mb-2 text-red-700">{{ $error }}</p>@endforeach
    @if ($records['setup_required'])
        <p>Vehicle records setup is pending.</p>
    @elseif (!$records['profile'])
        <p>No vehicle records submitted yet. Existing demo access is unchanged; this does not mean documents are verified.</p>
    @else
        @php($profile = $records['profile'])
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach (['plate_number' => 'Plate', 'vehicle_type' => 'Vehicle', 'capacity_kg' => 'Capacity (kg)', 'license_no' => 'Professional license', 'license_expiry' => 'Expiry', 'review_status' => 'Review status'] as $key => $label)
                <div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $profile[$key] }}</dd></div>
            @endforeach
            <div><dt class="font-semibold">License codes</dt><dd>{{ implode(', ', $profile['license_codes']) }}</dd></div>
        </dl>
        <p class="my-4">{{ $profile['review_note'] }}</p>
        <div class="my-4 flex flex-wrap gap-4">
            @foreach ($profile['documents'] as $key => $document)
                <a class="underline" href="{{ $document['download_path'] }}">Download {{ str_replace('_', ' ', $key) }}</a>
            @endforeach
        </div>
        @if ($profile['review_status'] === 'pending' && $records['editable'])
            <form method="POST" action="{{ route('logistics.couriers.vehicle.review', $driver) }}" class="mt-5 grid max-w-xl gap-3">
                @csrf
                <input type="hidden" name="version" value="{{ $profile['version'] }}">
                <label for="capacity">Confirmed capacity (kg)</label>
                <input id="capacity" type="number" name="capacity_kg" min="1" max="50000" step="0.01" value="{{ old('capacity_kg', $profile['capacity_kg']) }}" class="rounded border p-2">
                <label for="decision">Review decision</label>
                <select id="decision" name="decision" required class="rounded border p-2"><option value="">Select decision</option><option value="approved">Approve</option><option value="rejected">Request corrections</option></select>
                <label for="note">Review note</label>
                <textarea id="note" name="note" required maxlength="500" class="rounded border p-2">{{ old('note') }}</textarea>
                <p>Review all three documents and the vehicle details. This decision applies only to the displayed version.</p>
                <button class="rounded border px-4 py-2" type="submit">Save review</button>
            </form>
        @elseif (!$records['editable'])
            <p>Finish the active trip and go Off Duty before review.</p>
        @endif
    @endif
</section>
@endsection

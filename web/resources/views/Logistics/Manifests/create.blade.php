@extends('Layouts.logistics')

@section('title', 'Create Manifest')

@section('content')
<div class="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-[var(--border-strong)] pb-6">
    <div>
        <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Internal movements</p>
        <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">Create Manifest</h1>
        <p class="mt-2 max-w-2xl font-sans text-xs leading-relaxed text-[var(--text-muted)]">Choose two operational hubs inside {{ $provider->business_name }}. Parcels are added after the draft is created.</p>
    </div>
    <a href="{{ route('logistics.manifests.index') }}" class="inline-flex min-h-10 items-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-surface)]">Back to manifests</a>
</div>

@if ($errors->any())
    <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 font-sans text-[12px] text-rose-800">
        <p class="font-semibold">Please review the following before saving:</p>
        <ul class="mt-1 list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

@if ($hubs->count() < 2)
    <section class="rounded-lg border border-dashed border-[var(--border-strong)] bg-[var(--bg-surface)] px-6 py-12 text-center">
        <i data-lucide="warehouse" class="mx-auto mb-4 size-8 text-primary-400"></i>
        <h2 class="font-serif text-lg text-[var(--text-ink)]">Two operational hubs are required</h2>
        <p class="mx-auto mt-2 max-w-md font-sans text-xs leading-relaxed text-[var(--text-muted)]">A manifest needs an approved, active origin hub and a different destination hub. Add or activate hubs first.</p>
        <a href="{{ route('logistics.hubs.index') }}" class="mt-5 inline-flex min-h-9 items-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Manage hubs</a>
    </section>
@else
    <form method="POST" action="{{ route('logistics.manifests.store') }}" class="max-w-2xl rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 shadow-sm">
        @csrf

        <div class="space-y-5">
            <div>
                <label for="origin_hub_id" class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Origin hub</label>
                <select id="origin_hub_id" name="origin_hub_id" required class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2.5 font-sans text-[13px] text-[var(--text-ink)]">
                    <option value="">Select the hub currently holding the parcels</option>
                    @foreach ($hubs as $hub)
                        <option value="{{ $hub->id }}" @selected((string) old('origin_hub_id') === (string) $hub->id)>{{ $hub->name }} — {{ $hub->hub_type === \App\Models\Logistics\LogisticsHub::PROVINCE ? 'Province Hub' : 'Station Hub' }}</option>
                    @endforeach
                </select>
                @error('origin_hub_id')<p class="mt-1 font-sans text-[11px] text-rose-700">{{ $message }}</p>@enderror
            </div>

            <div class="flex justify-center">
                <span class="flex size-8 items-center justify-center rounded-full border border-[var(--border-strong)] bg-[var(--bg-page)] text-[var(--text-muted)]"><i data-lucide="arrow-down" class="size-3.5"></i></span>
            </div>

            <div>
                <label for="destination_hub_id" class="mb-1.5 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Destination hub</label>
                <select id="destination_hub_id" name="destination_hub_id" required class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2.5 font-sans text-[13px] text-[var(--text-ink)]">
                    <option value="">Select where these parcels are going</option>
                    @foreach ($hubs as $hub)
                        <option value="{{ $hub->id }}" @selected((string) old('destination_hub_id') === (string) $hub->id)>{{ $hub->name }} — {{ $hub->hub_type === \App\Models\Logistics\LogisticsHub::PROVINCE ? 'Province Hub' : 'Station Hub' }}</option>
                    @endforeach
                </select>
                @error('destination_hub_id')<p class="mt-1 font-sans text-[11px] text-rose-700">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="mt-6 flex items-start gap-2 rounded-md border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2.5 font-sans text-[11px] leading-relaxed text-[var(--text-muted)]">
            <i data-lucide="info" class="mt-0.5 size-3.5 shrink-0 text-primary-600"></i>
            <p>Same-station deliveries do not require a manifest. Only parcels physically at the origin hub can be added while this manifest is a draft.</p>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <a href="{{ route('logistics.manifests.index') }}" class="inline-flex min-h-10 items-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Cancel</a>
            <button type="submit" class="inline-flex min-h-10 items-center rounded-sm bg-primary-700 px-4 font-sans text-[11px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">Create draft</button>
        </div>
    </form>
@endif
@endsection

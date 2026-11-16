{{--
    Shared header for the Setup pages (hubs, transport connections,
    partnerships, delivery areas, activity log). Hosts the notification bell,
    so each page must also define @section('logistics-toolbar') with the
    hidden marker to stop the layout rendering a second bell.
    The slot holds page actions; they sit beside the bell.
--}}
@props(['title', 'description' => null, 'note' => null, 'noteIcon' => 'info'])

@php
    $provider = auth('logistics')->user();
    $province = $provider?->hasVerifiedProvince() ? $provider->province_name : null;
@endphp

<header {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 border-b border-[var(--border-strong)] pb-6 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0 flex-1">
        <p class="mb-2 font-sans text-[10px] font-semibold uppercase tracking-[0.18em] text-primary-700">Logistics network</p>
        <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)] sm:text-[32px]">{{ $title }}</h1>
        @if ($description)
            <p class="mt-2 max-w-2xl font-sans text-[13px] leading-relaxed text-[var(--text-ink)]/80">{{ $description }}</p>
        @endif
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 font-sans text-xs text-[var(--text-ink)]/80">
            @if ($provider)
                <span class="inline-flex items-center gap-1.5 rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-2.5 py-1">
                    <i data-lucide="{{ $province ? 'shield-check' : 'building-2' }}" class="size-3.5 text-primary-700" aria-hidden="true"></i>
                    <span class="font-semibold text-[var(--text-ink)]">{{ $provider->business_name }}</span>
                    @if ($province)
                        <span aria-hidden="true">·</span><span>{{ $province }}</span>
                    @endif
                </span>
            @endif
            @if ($note)
                <span class="inline-flex items-center gap-1.5"><i data-lucide="{{ $noteIcon }}" class="size-3.5" aria-hidden="true"></i>{{ $note }}</span>
            @endif
        </div>
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
        {{ $slot }}
        <x-logistics.notification-bell />
    </div>
</header>

{{--
    Full-bleed queue header. The layout skips its standalone bell when
    @section('logistics-toolbar') is defined, so the bell lives here instead.
--}}
@props(['title'])

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 py-3 sm:px-6 xl:px-8']) }}>
    <h1 class="min-w-0 font-serif text-2xl font-semibold text-[var(--text-ink)]">{{ $title }}</h1>
    <div class="flex shrink-0 items-center gap-2">
        {{ $slot }}
        <x-logistics.notification-bell />
    </div>
</div>

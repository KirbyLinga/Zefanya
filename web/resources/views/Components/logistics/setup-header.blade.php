@props(['title', 'description'])

<header class="mb-4 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="font-serif text-2xl text-[var(--text-ink)] sm:text-[28px]">{{ $title }}</h1>
        <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $description }}</p>
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</header>

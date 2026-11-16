@php
    // Colored pill for any state-driven value. Display text only changes underscores to spaces
    // (and shows "cod" as COD); the underlying value is never rewritten.
    $raw = trim((string) ($value ?? ''));
    $key = strtolower(str_replace([' ', '-'], '_', $raw));
    $text = $key === 'cod' ? 'COD' : str_replace('_', ' ', $raw);
    $tone = match (true) {
        (bool) preg_match('/cancel|fail|lost|return|reject|shortage|dispute|missing/', $key) => 'border-red-200 bg-red-50 text-red-800',
        (bool) preg_match('/unpaid|pending|outstanding|ready|hold|review|requested|not_/', $key) => 'border-amber-200 bg-amber-50 text-amber-900',
        (bool) preg_match('/^paid$|transit|dispatch|picked|assigned|out_for|accepted|sorted|arrived|sorting|preparing|placed|partial/', $key) => 'border-blue-200 bg-blue-50 text-blue-800',
        (bool) preg_match('/complet|deliver|confirmed|received|settled|finalized|verified|success|^yes$/', $key) => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        default => 'border-secondary-200 bg-secondary-100 text-primary-900',
    };
@endphp
@if ($raw !== '')<span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $tone }}">{{ $text }}</span>@endif

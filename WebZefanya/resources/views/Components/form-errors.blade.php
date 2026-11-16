@props(['messages' => null, 'show' => null, 'id' => null])

@php
    $formErrorMessages = collect($messages ?? $errors->all())->flatten()->filter()->unique()->values();
@endphp

@if ($show ?? $formErrorMessages->isNotEmpty())
    <div @if ($id) id="{{ $id }}" @endif tabindex="-1" role="alert" aria-atomic="true"
         {{ $attributes->class(['mb-4 min-w-0 rounded-lg border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-4 font-sans text-sm text-[var(--status-danger)]']) }}>
        <p class="font-semibold">Please check the form and correct the information below.</p>
        @if ($formErrorMessages->isNotEmpty())
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($formErrorMessages as $message)<li class="break-words">{{ $message }}</li>@endforeach
            </ul>
        @endif
        {{ $slot }}
    </div>
@endif

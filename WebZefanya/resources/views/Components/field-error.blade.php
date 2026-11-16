@props(['messages' => [], 'id'])

@php($fieldErrorMessages = collect($messages)->flatten()->filter()->unique())
@if ($fieldErrorMessages->isNotEmpty())
    <div id="{{ $id }}" {{ $attributes->class(['mt-1 break-words font-sans text-xs text-[var(--status-danger)]']) }}>
        @foreach ($fieldErrorMessages as $message)<p>{{ $message }}</p>@endforeach
    </div>
@endif

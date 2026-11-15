@props(['messages' => ['success', 'warning', 'error']])

@foreach ($messages as $type)
    @if (session($type))
        <x-flash :type="$type" :message="session($type)" spacing="mb-4" />
    @endif
@endforeach

@if ($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-4 font-sans text-sm text-[var(--status-danger)]">
        {{ $errors->first() }}
    </div>
@endif

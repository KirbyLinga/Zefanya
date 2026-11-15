@props(['messages' => ['success', 'warning', 'error']])

@foreach ($messages as $type)
    @if (session($type))
        <x-flash :type="$type" :message="session($type)" spacing="mb-4" />
    @endif
@endforeach

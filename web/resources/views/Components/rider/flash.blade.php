@props(['messages' => ['success', 'warning', 'error']])

@php
    $configs = [
        'success' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700'],
        'warning' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700'],
        'error'   => ['bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'text' => 'text-rose-700'],
    ];
@endphp

<div class="space-y-2">
    @foreach ($messages as $msg)
        @if (session($msg))
            @php($cfg = $configs[$msg])
            <div class="mb-4 font-sans text-[12.5px] font-semibold {{ $cfg['bg'] }} {{ $cfg['border'] }} {{ $cfg['text'] }} border rounded-lg px-3 py-2">
                {{ session($msg) }}
            </div>
        @endif
    @endforeach

    @if ($errors->any())
        <div class="mb-4 font-sans text-[12.5px] font-semibold bg-rose-50 border-rose-200 text-rose-700 border rounded-lg px-3 py-2">
            {{ $errors->first() }}
        </div>
    @endif
</div>

@props([
    'type' => 'success',
    'message' => null,
    'dismissible' => true,
    'icon' => true,
    'urgency' => 'polite',
    'spacing' => 'mb-4',
])

@php
    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'success';
    $config = [
        'success' => ['bg' => 'bg-[var(--pill-success-bg)]', 'border' => 'border-[var(--pill-success-border)]', 'text' => 'text-[var(--status-success)]', 'label' => 'Success'],
        'error' => ['bg' => 'bg-[var(--pill-danger-bg)]', 'border' => 'border-[var(--pill-danger-border)]', 'text' => 'text-[var(--status-danger)]', 'label' => 'Error'],
        'warning' => ['bg' => 'bg-[var(--pill-warning-bg)]', 'border' => 'border-[var(--status-warning)]', 'text' => 'text-[var(--status-warning)]', 'label' => 'Warning'],
        'info' => ['bg' => 'bg-[var(--pill-primary-bg)]', 'border' => 'border-[var(--border-strong)]', 'text' => 'text-[var(--text-ink)]', 'label' => 'Information'],
    ][$type];
    $role = $type === 'error' || ($type === 'warning' && $urgency === 'assertive') ? 'alert' : 'status';
@endphp

@if (filled($message))
    <div data-flash-message data-flash-type="{{ $type }}" role="{{ $role }}" aria-atomic="true"
         class="{{ $spacing }} flex min-w-0 items-start gap-3 rounded-lg border p-4 font-sans text-sm {{ $config['bg'] }} {{ $config['border'] }} {{ $config['text'] }}">
        @if ($icon)
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false">
                @if ($type === 'success')
                    <circle cx="10" cy="10" r="7.5" /><path d="m6.5 10.2 2.2 2.2 4.8-5" />
                @elseif ($type === 'error')
                    <circle cx="10" cy="10" r="7.5" /><path d="M10 6.2v4.5m0 3.1h.01" />
                @elseif ($type === 'warning')
                    <path d="M10 2.5 18 17H2L10 2.5Z" /><path d="M10 7v4.5m0 2.5h.01" />
                @else
                    <circle cx="10" cy="10" r="7.5" /><path d="M10 9v4.5m0-7h.01" />
                @endif
            </svg>
        @endif

        <div class="min-w-0 flex-1 break-words">
            <p>{{ $message }}</p>
            {{ $slot }}
        </div>

        @if ($dismissible)
            <button type="button" data-flash-dismiss aria-label="Dismiss {{ strtolower($config['label']) }} message"
                    class="-mr-1 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md opacity-75 transition-opacity hover:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
                    <path d="m5 5 10 10M15 5 5 15" />
                </svg>
            </button>
        @endif
    </div>
@endif

@once
    <noscript><style>button[data-flash-dismiss] { display: none !important; }</style></noscript>
    <script>
        document.addEventListener('click', function (event) {
            var dismiss = event.target.closest('[data-flash-dismiss]');
            if (!dismiss) return;
            var message = dismiss.closest('[data-flash-message]');
            if (message) message.remove();
        });
    </script>
@endonce

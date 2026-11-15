@php
    // $fields: label => value. $badges (optional): labels whose value is a state and renders as a pill.
    $badges = $badges ?? [];
    $mutedValues = ['Not recorded', 'Not yet verified', 'Unassigned', 'None recorded', 'No payout recorded', 'Not received', 'Not finalized', 'No payment date', ''];
    $iconFor = function (string $label): ?string {
        $l = strtolower($label);
        return match (true) {
            str_contains($l, 'buyer') || str_contains($l, 'recipient') => 'user',
            str_contains($l, 'seller') => 'store',
            str_contains($l, 'rider') || str_contains($l, 'courier') => 'bike',
            str_contains($l, 'logistics') || str_contains($l, 'provider') || str_contains($l, 'carrier') => 'truck',
            str_contains($l, 'hub') => 'warehouse',
            str_contains($l, 'verif') || str_contains($l, 'receipt') => 'shield-check',
            str_contains($l, 'status') => 'activity',
            str_contains($l, ' at') || str_contains($l, 'since') || str_contains($l, 'windows') => 'clock',
            str_contains($l, 'return') => 'undo-2',
            str_contains($l, 'refund') || str_contains($l, 'payable') || str_contains($l, 'payment') || str_contains($l, 'payout') || str_contains($l, 'amount')
                || str_contains($l, 'subtotal') || str_contains($l, 'fee') || str_contains($l, 'commission') || str_contains($l, 'due') || str_contains($l, 'cash') || str_contains($l, 'total')
                || str_contains($l, 'shipping') || str_contains($l, 'compensation') || str_contains($l, 'shortage') || str_contains($l, 'settlement') => 'banknote',
            str_contains($l, 'order') || str_contains($l, 'shipment') || str_contains($l, 'manifest') || str_contains($l, 'transfer') => 'package',
            default => null,
        };
    };
@endphp
<dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($fields as $label => $value)
        @php
            $shown = $value === null ? '' : ($value instanceof \DateTimeInterface ? manila($value, 'Y-m-d H:i:s') : (string) $value);
            $empty = in_array($shown, $mutedValues, true);
            $icon = $iconFor((string) $label);
        @endphp
        <div class="min-w-0 rounded-xl border p-3 {{ $empty ? 'border-neutral-100 bg-white' : 'border-neutral-200 bg-neutral-50' }}">
            <dt class="flex items-center gap-1.5 text-xs font-medium {{ $empty ? 'text-neutral-400' : 'text-neutral-600' }}">
                @if ($icon)<i data-lucide="{{ $icon }}" width="14" height="14" class="shrink-0 {{ $empty ? 'text-neutral-300' : 'text-primary-700' }}" aria-hidden="true"></i>@endif
                <span class="min-w-0">{{ $label }}</span>
            </dt>
            <dd class="mt-1.5 break-words text-sm {{ $empty ? 'font-normal italic text-neutral-400' : 'font-semibold text-neutral-950' }}">
                @if ($empty)
                    {{ $shown === '' ? 'Not recorded' : $shown }}
                @elseif (in_array($label, $badges, true))
                    @include('Admin.OrderMonitoring._badge', ['value' => $shown])
                @else
                    {{ $shown }}
                @endif
            </dd>
        </div>
    @endforeach
</dl>

{{--
    Contextual policy summary + links. Text is a short pointer, never a restatement of rates:
    amounts and rates come from the live configuration, not from here.
    Usage: @include('Policies._links', ['context' => 'seller-returns'])
--}}
@php
    $contexts = [
        'checkout' => [
            'summary' => 'Buyer protection: you can report a wrong, damaged, defective or incomplete item, or a parcel you did not receive, in the app within seven calendar days of recorded delivery. Your rights under Philippine consumer law are not limited by that period. Payment is cash on delivery for the amount shown above.',
            'slugs' => ['buyer-protection', 'terms-of-service', 'privacy-policy'],
        ],
        'buyer-order' => [
            'summary' => 'Need to report a problem? The in-app window is seven calendar days from recorded delivery, and applicable consumer rights continue beyond it through support.',
            'slugs' => ['buyer-protection', 'terms-of-service'],
        ],
        'seller-account' => [
            'summary' => 'Your Seller Agreement covers accurate, lawful listings, marking orders Ready for Pickup only when physically ready, the commission basis and rate schedule, Seller-funded discounts, payouts and debts, returns and refunds, and protection of Buyer data.',
            'slugs' => ['seller-agreement', 'terms-of-service', 'privacy-policy'],
        ],
        'seller-returns' => [
            'summary' => 'Sellers must handle genuine defective, wrong, damaged or incomplete complaints. Approved return, physical receipt, refund owed or paid, and stock restoration are separate steps.',
            'slugs' => ['seller-agreement', 'buyer-protection'],
        ],
        'seller-finance' => [
            'summary' => 'Payouts follow verified settlement records. Valid returns can create a documented debt that is offset only against future payouts under the approved rules.',
            'slugs' => ['seller-agreement'],
        ],
        'logistics' => [
            'summary' => 'Your Logistics Provider Agreement covers province and barangay coverage, verified parcel custody, handoffs between providers, COD cash handling, discrepancy reviews, worker responsibility, recovery when a worker is unavailable, hub delay escalation and recipient privacy.',
            'slugs' => ['logistics-agreement', 'terms-of-service', 'privacy-policy'],
        ],
        'logistics-cod' => [
            'summary' => 'Record the original COD due and the cash actually counted separately. Shortages are reviewed with the worker\'s explanation and are never deducted automatically.',
            'slugs' => ['logistics-agreement'],
        ],
        'logistics-incident' => [
            'summary' => 'Custody stays with the last verified holder until a documented physical handover. Hub delays follow the 24-hour, 48-hour, five-day and seven-day review steps.',
            'slugs' => ['logistics-agreement', 'rider-courier-rules'],
        ],
    ];
    $block = $contexts[$context] ?? null;
    $titles = collect(config('policies.documents'))->map->title;
@endphp
@if ($block)
    <aside aria-label="Policies" data-policy-links="{{ $context }}"
           class="mb-6 rounded-xl border border-neutral-200 bg-white p-4 text-[13px] leading-relaxed text-neutral-700">
        <p>{{ $block['summary'] }}</p>
        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($block['slugs'] as $slug)
                <a href="{{ route('policies.show', $slug) }}" class="font-semibold text-primary-800 underline underline-offset-2 hover:text-primary-900">{{ $titles[$slug] }}</a>
            @endforeach
        </p>
    </aside>
@endif

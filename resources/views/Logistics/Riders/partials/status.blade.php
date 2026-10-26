@php
    $badges = [
        'approved' => ['label' => 'Approved', 'classes' => 'bg-sky-50 border-sky-200 text-sky-700'],
        'active' => ['label' => 'Active', 'classes' => 'bg-emerald-50 border-emerald-200 text-emerald-700'],
        'pending' => ['label' => 'Pending Review', 'classes' => 'bg-amber-50 border-amber-200 text-amber-700'],
        'pending_activation' => ['label' => 'Pending Activation', 'classes' => 'bg-amber-50 border-amber-200 text-amber-700'],
        'unverified' => ['label' => 'Unverified', 'classes' => 'bg-amber-50 border-amber-200 text-amber-700'],
        'suspended' => ['label' => 'Suspended', 'classes' => 'bg-orange-50 border-orange-200 text-orange-700'],
        'deactivated' => ['label' => 'Deactivated', 'classes' => 'bg-rose-50 border-rose-200 text-rose-700'],
        'rejected' => ['label' => 'Rejected', 'classes' => 'bg-rose-50 border-rose-200 text-rose-700'],
    ];
    $badge = $badges[$status] ?? ['label' => ucfirst(str_replace('_', ' ', (string) $status)), 'classes' => 'bg-neutral-100 border-neutral-200 text-neutral-700'];
@endphp
<span class="inline-flex items-center rounded-full border px-2.5 py-0.5 font-sans text-[11px] font-semibold {{ $badge['classes'] }}">{{ $badge['label'] }}</span>

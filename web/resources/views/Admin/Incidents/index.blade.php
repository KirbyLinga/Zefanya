@extends('Admin.Layouts.app')

@section('title', 'Lost/Damaged Incidents')

@section('content')
    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-[28px] font-bold text-neutral-950 mb-1">Lost/Damaged Incidents</h1>
            <p class="text-base text-neutral-600">Unresolved lost and damaged incidents awaiting Admin resolution.</p>
        </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg overflow-hidden">
        @if ($incidents->count() > 0)
            <table class="w-full text-base">
                <thead class="bg-neutral-50 border-b border-neutral-200">
                    <tr>
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Parcel</th>
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Incident type</th>
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Reporter</th>
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Status</th>
                        <th class="px-6 py-4 text-left font-semibold text-neutral-700">Resolved</th>
                        <th class="px-6 py-4 text-center font-semibold text-neutral-700">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    @foreach ($incidents as $incident)
                        @php
                            $statusValue = $incident->status instanceof \BackedEnum ? $incident->status->value : $incident->status;
                            $opens = ($incident->eventCounts()['reported'] ?? 0);
                            $resolves = ($incident->eventCounts()['resolved'] ?? 0);
                        @endphp
                        <tr class="hover:bg-neutral-50">
                            <td class="px-6 py-4 font-semibold text-neutral-950">
                                Parcel #{{ $incident->incident_seller_order_id }}
                                @if ($incident->sellerOrder->tracking_number)
                                    <span class="ml-2 text-xs text-neutral-500">{{ $incident->sellerOrder->tracking_number }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-neutral-700">{{ $incident->type }}</td>
                            <td class="px-6 py-4 text-neutral-700">{{ $incident->reporter_type ?? '—' }} #{{ $incident->reporter_id ?? '—' }}</td>
                            <td class="px-6 py-4">
                                @if ($incident->isOpen())
                                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">{{ strtoupper($statusValue) }}</span>
                                @else
                                    <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">{{ strtoupper($statusValue) }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center text-neutral-500 text-xs">
                                {{ $resolves }}/{{ $opens }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="{{ route('admin.incidents.detail', $incident) }}" class="text-xs font-semibold text-primary-600 hover:text-primary-800">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-6 py-4 border-t border-neutral-200">
                {{ $incidents->links() }}
            </div>
        @else
            <div class="px-6 py-16 text-center text-neutral-500">
                <i data-lucide="bell-off" class="w-12 h-12 mx-auto mb-4 text-neutral-300"></i>
                <p class="font-semibold text-neutral-600">No incidents yet</p>
                <p class="text-sm text-neutral-500">Report lost/damaged parcels to see them here.</p>
            </div>
        @endif
    </div>
@endsection

@once
    @push('styles')
        <style>
            .relative {
                position: relative;
            }
        </style>
    @endpush
@endonce

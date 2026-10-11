@extends('Admin.Layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- admin-page-title / admin-page-subtitle → utilities (ADMIN-b):
         Playfair 28px / Montserrat 14px, margins from design-system
         (h1 margin 0 = preflight; subtitle margin 8px 0 0 = mt-2). --}}
    <h1 class="font-serif font-normal text-[28px] text-neutral-950">Welcome, {{ $admin->name }}</h1>
    <p class="font-sans font-normal text-sm text-neutral-600 mt-2">Here's what's happening across Zefanya today.</p>

    {{-- dash-summary-strip → utilities (ADMIN-b). admin.css used its own
         1200px / 700px breakpoints — kept as arbitrary max-width variants,
         NOT Tailwind's lg/sm. --}}
    <div class="grid grid-cols-4 gap-5 mt-6 max-[1200px]:grid-cols-2 max-[700px]:grid-cols-1">
        <div class="flex flex-col gap-2 p-5 bg-white border border-neutral-200 rounded-lg">
            <span class="font-serif font-normal text-[26px] text-neutral-950">{{ $activeUsers }}</span>
            <span class="font-sans text-[11.5px] font-semibold tracking-[0.4px] text-neutral-600">Active Users</span>
        </div>
        <div class="flex flex-col gap-2 p-5 bg-white border border-neutral-200 rounded-lg">
            <span class="font-serif font-normal text-[26px] text-neutral-950">{{ $pendingRegistrations }}</span>
            <span class="font-sans text-[11.5px] font-semibold tracking-[0.4px] text-neutral-600">Pending Registrations</span>
        </div>
        {{-- No disputes table exists yet, so this card states "—" rather than a number. --}}
        <div class="flex flex-col gap-2 p-5 bg-white border border-neutral-200 rounded-lg">
            <span class="font-serif font-normal text-[26px] text-neutral-950">—</span>
            <span class="font-sans text-[11.5px] font-semibold tracking-[0.4px] text-neutral-600">Open Disputes</span>
        </div>
        {{-- No commission/revenue source exists yet (payments never settle), so this card states "—". --}}
        <div class="flex flex-col gap-2 p-5 bg-white border border-neutral-200 rounded-lg">
            <span class="font-serif font-normal text-[26px] text-neutral-950">—</span>
            <span class="font-sans text-[11.5px] font-semibold tracking-[0.4px] text-neutral-600">Commission (This Month)</span>
        </div>
    </div>
@endsection

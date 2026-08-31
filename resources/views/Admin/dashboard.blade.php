@extends('Admin.Layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="admin-page-title">Welcome, {{ $admin->name }}</h1>
    <p class="admin-page-subtitle">Here's what's happening across Zefanya today.</p>

    @php
        $buyersTableExists = \Illuminate\Support\Facades\Schema::hasTable('buyers');
        $pendingCount = $buyersTableExists
            ? \App\Models\Buyer::where('status', 'pending')->count()
            : 0;
    @endphp

    <div class="dash-summary-strip">
        <div class="dash-summary-item">
            <span class="dash-summary-item__value">128</span>
            <span class="dash-summary-item__label">Active Users</span>
        </div>
        <div class="dash-summary-item">
            <span class="dash-summary-item__value">{{ $pendingCount }}</span>
            <span class="dash-summary-item__label">Pending Registrations</span>
        </div>
        <div class="dash-summary-item">
            <span class="dash-summary-item__value">5</span>
            <span class="dash-summary-item__label">Open Disputes</span>
        </div>
        <div class="dash-summary-item">
            <span class="dash-summary-item__value">₱12,450</span>
            <span class="dash-summary-item__label">Commission (This Month)</span>
        </div>
    </div>
@endsection

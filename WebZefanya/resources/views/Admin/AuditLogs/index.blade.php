@extends('Admin.Layouts.app')

@section('title', 'Audit Log — Zefanya Admin')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Platform settings', 'route' => 'admin.settings.index'],
    ['label' => 'Audit log', 'route' => 'admin.audit-logs.index'],
]" />
    <div class="mb-6">
        <h1 class="font-serif text-3xl text-neutral-950">Audit Log</h1>
        <p class="mt-2 text-sm text-neutral-600">A permanent record of Logistics rider decisions, manual overrides, rate changes and cash confirmations. It cannot be edited or deleted.</p>
    </div>

    <x-operations-audit-table :logs="$logs" :action="$action" />
@endsection

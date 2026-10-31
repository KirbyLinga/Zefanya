@extends('Layouts.logistics')

@section('title', 'Activity Log')

@section('content')
<div class="mb-5">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Activity Log</h1>
    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">A permanent record of rider decisions, manual overrides, rate changes and cash confirmations. It cannot be edited or deleted.</p>
</div>

<x-operations-audit-table :logs="$logs" :action="$action" />
@endsection

@extends('Layouts.logistics')

@section('title', 'Activity Log')
@section('logistics-toolbar')<span hidden data-logistics-hosted-bell></span>@endsection

@section('content')
<x-logistics.tabs group="setup" />
<x-logistics.setup-header title="Activity Log" description="A permanent record of rider decisions, manual overrides, rate changes and cash confirmations." note="Read-only history. It cannot be edited or deleted." note-icon="lock-keyhole" />

<section id="logistics-audit-table" aria-labelledby="audit-records-title">
    <div class="mb-3 flex items-center justify-between gap-3 font-sans">
        <h2 id="audit-records-title" class="text-sm font-semibold text-[var(--text-ink)]">Activity records <span class="ml-1 rounded-md bg-[var(--bg-surface)] px-2 py-0.5 text-xs">{{ $logs->total() }}</span></h2>
        @if ($action)
            <a href="{{ route('logistics.audit-logs.index') }}" class="inline-flex min-h-10 items-center gap-1.5 rounded-[7px] border border-[var(--border-btn-outline)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700"><i data-lucide="rotate-ccw" class="size-3.5" aria-hidden="true"></i>Reset filter</a>
        @endif
    </div>
    <x-operations-audit-table :logs="$logs" :action="$action" />
</section>
@endsection

@push('styles')
<style>
    #logistics-audit-table > form { align-items: end; gap: 10px; padding: 14px 16px; border: 1px solid var(--border-strong); border-radius: 10px; background: var(--bg-surface); }
    #logistics-audit-table > form label { align-self: center; font-weight: 600; color: var(--text-ink); }
    #logistics-audit-table select { min-width: 200px; height: 40px; padding: 0 12px; border-color: var(--border-input); border-radius: 7px; font-size: 13px; }
    #logistics-audit-table > form button { height: 40px; border-radius: 7px; background: var(--bg-raised); }
    #logistics-audit-table :is(select, button):focus-visible { outline: 2px solid var(--link); outline-offset: 2px; }
    #logistics-audit-table .overflow-x-auto { position: relative; }
    #logistics-audit-table thead { background: var(--bg-raised); color: var(--text-ink); }
    #logistics-audit-table thead th { padding-top: 12px; padding-bottom: 12px; font-size: 12px; }
    #logistics-audit-table tbody td { overflow-wrap: anywhere; }
    #logistics-audit-table tbody td:nth-child(4) { min-width: 220px; max-width: 400px; }
    #logistics-audit-table tbody td:nth-child(5) { min-width: 160px; max-width: 300px; }
    #logistics-audit-table [class*="text-[var(--text-muted)]"] { color: color-mix(in srgb, var(--text-ink) 80%, transparent); }
    #logistics-audit-table > p { padding: 20px 16px; }
    @media (max-width: 639px) {
        #logistics-audit-table > form label { width: 100%; }
        #logistics-audit-table select { min-width: 0; flex: 1; }
    }
</style>
@endpush

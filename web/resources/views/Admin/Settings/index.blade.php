@extends('Admin.Layouts.app')

@section('title', 'Platform Settings — Zefanya Admin')

@section('content')
<x-admin.tabs :tabs="[
    ['label' => 'Platform settings', 'route' => 'admin.settings.index'],
    ['label' => 'Audit log', 'route' => 'admin.audit-logs.index'],
]" />
    <div class="mb-6">
        <h1 class="font-serif text-3xl text-neutral-950">Platform Settings</h1>
        <p class="mt-2 text-sm text-neutral-600">Manage platform records and review their change history.</p>
    </div>

    <div class="mb-6 rounded-lg border border-primary-200 bg-white p-5 text-sm text-neutral-700">
        These records are stored for administrative use. Saving them does not change the live website, email delivery,
        commission, order timers, voucher calculations or Rider assignments. Payment details are shown to Logistics companies on their Earnings page.
    </div>

    @if (session('success'))
        <div role="status" class="mb-6 rounded-lg border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg border border-error bg-white p-4 text-sm text-error">
            <p class="font-semibold">Please review the following:</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-8 grid gap-4 lg:grid-cols-2">
        @foreach ($settings as $setting)
            @php($definition = $definitions[$setting->key])
            <form method="POST" action="{{ route('admin.settings.update', $setting) }}" class="rounded-lg border border-neutral-200 bg-white p-5">
                @csrf
                @method('PATCH')
                <input type="hidden" name="expected_value" value="{{ $setting->value }}">
                <label for="setting-{{ $setting->key }}" class="mb-2 block text-sm font-semibold text-neutral-950">{{ $definition['label'] }}</label>
                @if ($definition['type'] === 'textarea')
                    <textarea id="setting-{{ $setting->key }}" name="value" rows="3" required placeholder="e.g. GCash: Zefanya Inc., 0917 123 4567"
                              class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-950 focus:border-primary-400 focus:ring-2 focus:ring-primary-400">{{ $setting->value }}</textarea>
                @else
                    <input id="setting-{{ $setting->key }}" name="value" type="{{ $definition['type'] }}" value="{{ $setting->value }}" required
                           class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-950 focus:border-primary-400 focus:ring-2 focus:ring-primary-400">
                @endif
                <button type="submit" class="mt-4 cursor-pointer rounded-lg bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Save {{ strtolower($definition['label']) }}</button>
            </form>
        @endforeach
    </div>

    <section aria-labelledby="settings-history-heading" class="overflow-hidden rounded-lg border border-neutral-200 bg-white">
        <div class="border-b border-neutral-200 p-5">
            <h2 id="settings-history-heading" class="font-serif text-xl text-neutral-950">Change history</h2>
            <p class="mt-1 text-sm text-neutral-600">History is read-only. Timestamps are shown in {{ config('app.timezone') }}.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-neutral-700">
                    <tr>
                        <th scope="col" class="px-5 py-3">Setting</th>
                        <th scope="col" class="px-5 py-3">Previous value</th>
                        <th scope="col" class="px-5 py-3">New value</th>
                        <th scope="col" class="px-5 py-3">Changed by</th>
                        <th scope="col" class="px-5 py-3">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 text-neutral-700">
                    @forelse ($history as $entry)
                        <tr>
                            <td class="px-5 py-4">{{ $definitions[$entry->setting_key]['label'] ?? $entry->setting_key }}</td>
                            <td class="max-w-xs break-words px-5 py-4">{{ $entry->previous_value }}</td>
                            <td class="max-w-xs break-words px-5 py-4">{{ $entry->new_value }}</td>
                            <td class="px-5 py-4">{{ $entry->admin->name }} <span class="text-xs">(ID {{ $entry->admin_id }})</span></td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $entry->created_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-neutral-600">No settings have been changed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($history->hasPages())
            <div class="border-t border-neutral-200 p-5">{{ $history->links() }}</div>
        @endif
    </section>
@endsection

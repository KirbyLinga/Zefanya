@props(['logs', 'action' => null])

@use('App\Models\Admin\OperationsAuditLog')
@use('App\Models\Seller\StoreSale')

<form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
    <label for="audit-action" class="font-sans text-[13px] text-[var(--text-muted)]">Show</label>
    <select id="audit-action" name="action" class="h-9 rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-2 font-sans text-[13px] text-[var(--text-ink)]">
        <option value="">All activity</option>
        @foreach (OperationsAuditLog::ACTIONS as $value => $label)
            <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="inline-flex h-9 items-center rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[13px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Apply</button>
</form>

@if ($logs->isEmpty())
    <p class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-8 text-center font-sans text-[13px] text-[var(--text-muted)]">No activity recorded yet.</p>
@else
    <div class="overflow-x-auto rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <table class="w-full min-w-[820px] text-left font-sans text-[13px]">
            <thead class="border-b border-[var(--border-strong)] text-[12px] text-[var(--text-muted)]">
                <tr>
                    <th scope="col" class="px-4 py-2.5 font-semibold">When</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Who</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">What</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Change</th>
                    <th scope="col" class="px-4 py-2.5 font-semibold">Reason</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border-strong)]">
                @foreach ($logs as $log)
                    <tr class="align-top">
                        <td class="whitespace-nowrap px-4 py-3 text-[var(--text-muted)]">{{ $log->created_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</td>
                        <td class="px-4 py-3 text-[var(--text-ink)]">{{ $log->actor_name }}</td>
                        <td class="px-4 py-3">
                            <p class="font-semibold text-[var(--text-ink)]">{{ $log->actionLabel() }}</p>
                            <p class="text-[12px] text-[var(--text-muted)]">{{ $log->entity_label }}</p>
                        </td>
                        <td class="px-4 py-3 text-[var(--text-ink)]">
                            @foreach ($log->changes() as $change)
                                <p class="text-[var(--text-ink)]"><span class="text-[12px] text-[var(--text-muted)]">{{ $change['field'] }}:</span> {{ $change['old'] ?? '—' }} → {{ $change['new'] ?? '—' }}</p>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-[var(--text-ink)]">{{ $log->reason ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endif

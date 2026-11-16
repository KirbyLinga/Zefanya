@extends('Layouts.rider')

@section('title', 'Notifications')

@section('content')
    <x-rider.flash />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="font-sans text-[11px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Updates</p>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">Notifications</h1>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">
                Pickup and delivery assignments, station changes and account updates.
            </p>
        </div>

        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('rider.notifications.readAll') }}">
                @csrf
                <button
                    type="submit"
                    class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--sidebar-hover)]"
                >
                    Mark all as read
                </button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <section class="flex flex-col items-center rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-10 text-center shadow-sm">
            <i data-lucide="bell-off" class="mb-3 h-[34px] w-[34px] text-[var(--icon-soft)]" aria-hidden="true"></i>
            <p class="mb-1 font-sans text-[13px] font-semibold text-[var(--text-ink)]">No notifications yet</p>
            <p class="max-w-[400px] font-sans text-[11.5px] text-[var(--text-muted)]">
                When Logistics assigns you a pickup or delivery, moves your Station Hub or updates your account, you will see it here.
            </p>
        </section>
    @else
        <ul class="divide-y divide-[var(--border-strong)] overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm" role="list">
            @foreach ($notifications as $notification)
                @php($data = $notification->data ?? [])
                <li class="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            @if ($notification->read_at === null)
                                <span class="rounded-full bg-primary-100 px-2 py-0.5 font-sans text-[9.5px] font-bold uppercase tracking-[0.5px] text-primary-800">New</span>
                            @endif
                            <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">
                                {{ $data['title'] ?? 'Notification' }}
                            </p>
                        </div>

                        @if (! empty($data['message']))
                            <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $data['message'] }}</p>
                        @endif

                        <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">
                            {{ $notification->created_at?->diffForHumans() ?? '' }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('rider.notifications.open', $notification->id) }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex h-8 items-center justify-center rounded-sm bg-primary-700 px-3 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800"
                            >
                                Open
                            </button>
                        </form>

                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('rider.notifications.markRead', $notification->id) }}">
                                @csrf
                                <button
                                    type="submit"
                                    class="inline-flex h-8 items-center justify-center rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--sidebar-hover)]"
                                >
                                    Mark read
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            {{ $notifications->links() }}
        </div>
    @endif
@endsection

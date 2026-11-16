@extends('Layouts.logistics')

@section('title', 'Notifications')

@section('content')
<header class="mb-5">
    <h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)]">Notifications</h1>
    <p class="mt-1 font-sans text-sm text-[var(--text-muted)]">Parcel, rider, handoff and return alerts for your company. {{ $unreadCount }} unread.</p>
</header>

<x-logistics-flash :messages="['success']" />

<section aria-label="Your notifications" class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-5 py-3">
        <nav aria-label="Notification filters" class="flex gap-2">
            @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $label)
                <a href="{{ route('logistics.notifications.index', ['filter' => $value]) }}" @if ($filter === $value) aria-current="page" @endif
                   class="inline-flex min-h-9 items-center rounded-md px-3 font-sans text-sm font-semibold {{ $filter === $value ? 'bg-primary-800 text-white' : 'text-[var(--text-ink)] hover:bg-[var(--bg-page)]' }}">{{ $label }}</a>
            @endforeach
        </nav>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('logistics.notifications.readAll') }}">
                @csrf
                <button type="submit" class="inline-flex min-h-9 items-center rounded-md border border-[var(--border-strong)] px-3 font-sans text-sm font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Mark all as read</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <div class="px-5 py-14 text-center">
            <i data-lucide="bell-check" class="mx-auto mb-3 size-8 text-primary-700" aria-hidden="true"></i>
            <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">You're all caught up</h2>
            <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">{{ $filter === 'unread' ? 'You have no unread notifications.' : 'Hub alerts will show up here.' }}</p>
        </div>
    @else
        <ul class="divide-y divide-[var(--border-strong)]">
            @foreach ($notifications as $notification)
                @php $unread = $notification->read_at === null; @endphp
                <li class="flex flex-col gap-3 border-l-[3px] px-5 py-4 sm:flex-row sm:items-center sm:gap-6 {{ $unread ? 'border-l-primary-700 bg-primary-50/40' : 'border-l-transparent' }}">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 font-sans text-sm font-semibold text-[var(--text-ink)]">
                            {{ $notification->data['title'] ?? 'Notification' }}
                            @if ($unread)<span class="rounded-full bg-primary-800 px-2 py-0.5 text-[11px] font-semibold text-white">New</span>@endif
                        </p>
                        <p class="mt-1 font-sans text-sm text-[var(--text-muted)]">{{ $notification->data['message'] ?? '' }}</p>
                        <p class="mt-1 font-sans text-xs text-[var(--text-muted)]">{{ $notification->created_at?->diffForHumans() }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('logistics.notifications.open', $notification->id) }}">
                            @csrf
                            <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-primary-800 px-3 font-sans text-xs font-semibold text-white hover:bg-primary-900">Open</button>
                        </form>
                        @if ($unread)
                            <form method="POST" action="{{ route('logistics.notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-9 items-center rounded-md border border-[var(--border-strong)] px-3 font-sans text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">Mark as read</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<div class="mt-4">{{ $notifications->links() }}</div>
@endsection

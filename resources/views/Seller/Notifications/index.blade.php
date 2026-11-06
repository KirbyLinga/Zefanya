@extends('Layouts.seller')
@section('title', 'Notifications')
@section('content')
@php
    $timezone = \App\Models\Seller\StoreSale::TIMEZONE;
    $today = now($timezone);
    $days = $notifications->getCollection()->groupBy(function ($notification) use ($timezone, $today) {
        $sent = $notification->created_at?->copy()->setTimezone($timezone);

        return match (true) {
            $sent === null => 'Earlier',
            $sent->isSameDay($today) => 'Today',
            $sent->isSameDay($today->copy()->subDay()) => 'Yesterday',
            default => $sent->format('l, F j, Y'),
        };
    });
    $tones = [
        'primary' => 'bg-[var(--pill-primary-bg)] text-[var(--brand)]',
        'warning' => 'bg-[var(--pill-warning-bg)] text-[var(--status-warning)]',
        'danger' => 'bg-[var(--pill-danger-bg)] text-[var(--status-danger)]',
        'success' => 'bg-[var(--pill-success-bg)] text-[var(--status-success)]',
        'neutral' => 'bg-[var(--bg-chip)] text-[var(--text-muted)]',
    ];
@endphp
<div>
    <header class="mb-5">
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Notifications</h1>
        <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">Orders, returns, deliveries and low-stock alerts. {{ $unreadCount }} unread.</p>
    </header>
    @if (session('success'))
        <p role="status" class="mb-5 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 font-sans text-sm text-[var(--text-ink)]">{{ session('success') }}</p>
    @endif

    <section aria-label="Your notifications" class="overflow-hidden rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-strong)] px-5 py-3">
            <nav aria-label="Notification filters" class="flex flex-wrap gap-2 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">
                @foreach (['all' => 'All notifications', 'unread' => 'Unread'] as $value => $label)
                    <a href="{{ route('seller.notifications.index', ['filter' => $value]) }}" @if ($filter === $value) aria-current="page" @endif class="inline-flex min-h-11 items-center justify-center rounded-lg border px-4 py-2 font-sans text-[12.5px] font-semibold no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 {{ $filter === $value ? 'border-primary-800 bg-primary-800 text-white' : 'border-transparent text-[var(--text-ink)] hover:border-primary-200 hover:bg-primary-50 hover:text-primary-900' }}">{{ $label }}@if ($value === 'unread' && $unreadCount > 0) ({{ $unreadCount }})@endif</a>
                @endforeach
            </nav>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('seller.notifications.readAll') }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 font-sans text-sm font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">Mark all as read</button>
                </form>
            @endif
        </div>

        @if ($notifications->isEmpty())
            <div class="px-5 py-14 text-center">
                <i data-lucide="bell-check" class="mx-auto mb-3 size-8 text-[var(--brand)]" aria-hidden="true"></i>
                <h2 class="font-sans text-base font-semibold text-[var(--text-ink)]">You're all caught up</h2>
                <p class="mt-2 font-sans text-sm text-[var(--text-muted)]">{{ $filter === 'unread' ? 'You have no unread notifications.' : 'New orders, returns and low-stock alerts will appear here.' }}</p>
            </div>
        @else
            @foreach ($days as $day => $rows)
                <h2 class="border-b border-[var(--border-strong)] bg-[var(--bg-chip)] px-5 py-2 font-sans text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] {{ $loop->first ? '' : 'border-t' }}">{{ $day }}</h2>
                <ul class="divide-y divide-[var(--border-strong)]">
                    @foreach ($rows as $notification)
                        @php
                            $view = \App\Support\SellerNotificationPresenter::present($notification);
                            $unread = $notification->read_at === null;
                            $sent = $notification->created_at;
                        @endphp
                        <li data-notification data-unread="{{ $unread ? '1' : '0' }}" class="flex flex-col gap-3 border-l-[3px] px-5 py-4 sm:flex-row sm:items-center sm:gap-6 {{ $unread ? 'border-l-[var(--brand)] bg-[var(--pill-primary-bg)]' : 'border-l-transparent' }}">
                            <div class="flex min-w-0 flex-1 gap-4">
                                {{-- The solid backing keeps the tinted circle visible on a tinted unread row. --}}
                                <span class="size-10 shrink-0 rounded-full bg-[var(--bg-surface)]">
                                    <span class="flex size-10 items-center justify-center rounded-full {{ $tones[$view['tone']] }}">
                                        <i data-lucide="{{ $view['icon'] }}" class="size-5" aria-hidden="true"></i>
                                    </span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <h3 class="break-words font-sans text-[15px] font-semibold text-[var(--text-ink)]">{{ $view['title'] }}</h3>
                                        @if ($view['amountMinor'] !== null)
                                            <span class="font-sans text-sm font-semibold text-[var(--text-ink)]">· ₱{{ number_format($view['amountMinor'] / 100, 2) }}</span>
                                        @endif
                                        @if ($unread)
                                            <span class="rounded-full bg-primary-800 px-2 py-0.5 font-sans text-[11px] font-semibold text-white">New</span>
                                        @else
                                            <span class="sr-only">Read</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 whitespace-pre-line break-words font-sans text-sm {{ $unread ? 'text-[var(--text-ink)]' : 'text-[var(--text-muted)]' }}">{{ $notification->data['message'] ?? '' }}</p>
                                    <time class="mt-1 block font-sans text-xs text-[var(--text-muted)]" datetime="{{ $sent?->toIso8601String() }}" title="{{ $sent?->copy()->setTimezone($timezone)->format('M j, Y g:i A') }}">{{ $sent?->diffForHumans() }}</time>
                                </div>
                            </div>
                            <div class="flex min-w-0 flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
                                @if ($view['actionUrl'])
                                    <a href="{{ $view['actionUrl'] }}" class="inline-flex items-center gap-1 whitespace-nowrap font-sans text-sm font-semibold text-[var(--link)] no-underline hover:underline">{{ $view['actionLabel'] }} <i data-lucide="arrow-right" class="size-4" aria-hidden="true"></i></a>
                                @endif
                                @if ($unread)
                                    <form method="POST" action="{{ route('seller.notifications.read', $notification->id) }}">
                                        @csrf
                                        <button type="submit" class="whitespace-nowrap rounded-md border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 py-1.5 font-sans text-[13px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-soft)]">Mark as read</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
    </section>
    <div class="mt-5">{{ $notifications->links() }}</div>
</div>
@endsection

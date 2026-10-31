@extends('Layouts.seller')

@section('title', 'Messages')

@section('content')
<div class="mx-auto w-full max-w-[1200px] font-sans">
    <div class="mb-5 flex items-center justify-between gap-4">
        <div>
            <h1 class="font-serif text-2xl text-[var(--text-ink)]">Messages</h1>
            <p class="mt-1 text-xs text-[var(--text-muted)]">Stay in touch with your buyer.</p>
        </div>
        <a href="{{ route('seller.messages.index') }}" class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-xs font-semibold text-[var(--link)] transition-colors hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
            <i data-lucide="arrow-left" class="size-4" aria-hidden="true"></i>
            Back to messages
        </a>
    </div>

    <div class="flex h-[calc(100dvh-13rem)] min-h-[520px] flex-col overflow-hidden rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] shadow-sm">
        <div class="flex shrink-0 items-center gap-3 border-b border-[var(--border-strong)] px-5 py-4 sm:px-6">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-900">
                <i data-lucide="user-round" class="size-5" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <h2 class="truncate text-sm font-semibold text-[var(--text-ink)]">{{ $conversation->buyer?->fullName() ?? 'Buyer' }}</h2>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--text-muted)]">
                    <span class="text-inherit">Buyer</span>
                    @if($conversation->product)
                        <span class="text-inherit" aria-hidden="true">&middot;</span>
                        <span class="text-inherit">{{ Str::limit($conversation->product->name, 50) }}</span>
                    @elseif($conversation->order)
                        <span class="text-inherit" aria-hidden="true">&middot;</span>
                        <span class="rounded-md bg-primary-50 px-2 py-0.5 font-medium text-primary-900">Order #{{ $conversation->order->id }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if($conversation->product)
            <div class="shrink-0 px-5 pt-4 [&>a]:mb-0">
                @include('Messages._product-card')
            </div>
        @endif

        <div id="messagesContainer" role="log" aria-label="Conversation messages" class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain bg-[var(--bg-page)] p-5 sm:p-6 [&_[data-message-id]>div]:max-w-[85%] [&_[data-message-id]>div]:rounded-2xl [&_[data-message-id]>div]:px-4 [&_[data-message-id]>div]:py-3 sm:[&_[data-message-id]>div]:max-w-[70%] [&_[data-message-id]_p]:text-sm [&_[data-message-id]_p]:leading-relaxed">
            @forelse($messages as $message)
                @include('Messages._bubble', ['role' => 'seller'])
            @empty
                <div id="emptyThread" class="flex h-full flex-col items-center justify-center px-4 py-10 text-center">
                    <div class="mb-4 flex size-16 items-center justify-center rounded-2xl border border-primary-200 bg-primary-50 text-primary-800">
                        <i data-lucide="messages-square" class="size-7" aria-hidden="true"></i>
                    </div>
                    <h3 class="text-base font-semibold text-[var(--text-ink)]">Start the conversation</h3>
                    <p class="mt-2 max-w-xs text-sm leading-relaxed text-[var(--text-muted)]">Send a message to help your buyer with their order or answer a question.</p>
                </div>
            @endforelse
        </div>

        <div class="shrink-0 border-t border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 sm:px-6 sm:py-5 [&_textarea]:min-h-20 [&_textarea]:rounded-xl [&_textarea]:border-[var(--border-strong)] [&_textarea]:bg-[var(--bg-page)] [&_textarea]:p-3 [&_textarea]:text-[var(--text-ink)] [&_textarea]:focus:ring-primary-300 [&_input[type=file]]:mt-2 [&_input[type=file]]:max-w-full [&_input[type=file]]:file:mr-3 [&_input[type=file]]:file:cursor-pointer [&_input[type=file]]:file:rounded-lg [&_input[type=file]]:file:border-0 [&_input[type=file]]:file:bg-primary-50 [&_input[type=file]]:file:px-3 [&_input[type=file]]:file:py-2 [&_input[type=file]]:file:font-semibold [&_input[type=file]]:file:text-primary-900 [&_button]:min-h-10 [&_button]:rounded-lg [&_button]:px-6">
            <label for="messageInput" class="mb-2 block text-xs font-semibold text-[var(--text-ink)]">Your message</label>
            @include('Messages._composer', ['role' => 'seller'])
        </div>
    </div>
</div>
@endsection
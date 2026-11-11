@php
    $selectedConversation = $conversation ?? null;
    $totalUnread = array_sum($unreadCounts);
    $floating = $floating ?? false;
@endphp
@include('Buyer.Messages._styles')
<section id="buyer-messaging" class="{{ $selectedConversation ? 'has-thread' : '' }} {{ $floating ? 'is-widget' : '' }}" aria-label="Messages" data-chat-widget="{{ $floating ? 'true' : 'false' }}" data-conversation-id="{{ $selectedConversation?->id }}">
    <header class="messaging-title">
        <span class="title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg></span>
        <div><div class="title-line"><h1>Messages</h1>@if($totalUnread)<span class="unread-total">{{ $totalUnread }} unread</span>@endif</div><p>Your conversations with sellers</p></div>
    </header>
    <div class="messaging-grid">
        <aside class="inbox-panel" aria-label="Conversations">
            <div class="inbox-controls">
                <label class="conversation-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="conversationSearch" type="search" placeholder="Search conversations..." aria-label="Search conversations"></label>
                <div class="conversation-filters" aria-label="Filter conversations">
                    <button type="button" data-filter="all" aria-pressed="true">All ({{ $conversations->count() }})</button>
                    <button type="button" data-filter="unread" aria-pressed="false">Unread ({{ collect($unreadCounts)->filter()->count() }})</button>
                    <button type="button" data-filter="orders" aria-pressed="false">Orders</button>
                </div>
            </div>
            <nav class="conversation-list" aria-label="Choose a conversation">
                @forelse($conversations as $inboxConversation)
                    @php
                        $sellerName = $inboxConversation->seller->business_name ?? 'Seller';
                        $unread = $unreadCounts[$inboxConversation->id] ?? 0;
                        $isSelected = $selectedConversation?->id === $inboxConversation->id;
                    @endphp
                    <a href="{{ route('buyer.messages.show', ['conversation' => $inboxConversation, 'widget' => $floating ? 1 : null]) }}" target="_self" class="conversation-row {{ $isSelected ? 'is-selected' : '' }}" @if($isSelected) aria-current="page" @endif data-conversation data-unread="{{ $unread }}" data-order="{{ $inboxConversation->order_id ? '1' : '0' }}">
                        <span class="seller-avatar" aria-hidden="true">{{ mb_substr($sellerName, 0, 1) }}</span>
                        <span class="conversation-copy"><strong>{{ $sellerName }}</strong><span class="conversation-context">@if($inboxConversation->order)Order #{{ $inboxConversation->order->id }}@elseif($inboxConversation->product){{ $inboxConversation->product->name }}@else No order yet @endif</span><span class="conversation-preview">{{ $inboxConversation->messages->last()?->preview() ?? 'Start a conversation' }}</span></span>
                        <span class="conversation-meta"><time>{{ $inboxConversation->last_message_at?->isToday() ? $inboxConversation->last_message_at->format('g:i A') : $inboxConversation->last_message_at?->format('M j') }}</time>@if($unread)<span class="unread-count">{{ $unread }}</span>@endif</span>
                    </a>
                @empty
                    <p class="inbox-empty">No messages yet. Start a conversation with a seller to begin messaging.</p>
                @endforelse
                <p id="noConversations" class="inbox-empty" hidden>No conversations match your search.</p>
            </nav>
        </aside>
        <section class="thread-panel" aria-label="{{ $selectedConversation ? 'Conversation with ' . ($selectedConversation->seller->business_name ?? 'Seller') : 'Select a conversation' }}">
            @if($selectedConversation)
                <header class="thread-header">
                    <a class="mobile-back" target="_self" href="{{ route('buyer.messages.index', $floating ? ['widget' => 1] : []) }}" aria-label="Back to conversations">&larr;</a>
                    <span class="seller-avatar" aria-hidden="true">{{ mb_substr($conversation->seller->business_name ?? 'Seller', 0, 1) }}</span>
                    <div class="thread-person"><h2>{{ $conversation->seller->business_name ?? 'Seller' }}</h2><p>@if($conversation->order)Order #{{ $conversation->order->id }}@elseif($conversation->product){{ $conversation->product->name }}@else Seller conversation @endif</p></div>
                    @if($conversation->order)
                        <a class="view-order" href="{{ route('buyer.orders.show', $conversation->order) }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm0 10v9M3 8l9 5 9-5M7.5 5.5l9 5"/></svg><span>View Order</span></a>
                    @endif
                </header>
                @if($conversation->product)<div class="thread-product">@include('Messages._product-card')</div>@endif
                <div id="messagesContainer" class="thread-messages" aria-label="Messages in this conversation">
                    @forelse($messages as $message)
                        @include('Messages._bubble', ['role' => 'buyer'])
                    @empty
                        <div id="emptyThread" class="thread-empty"><span class="empty-chat-icon" aria-hidden="true">&hellip;</span><h3>Start the conversation</h3><p>Send a message or share a photo with {{ $conversation->seller->business_name ?? 'the seller' }}.</p></div>
                    @endforelse
                </div>
                <div class="thread-composer">@include('Messages._composer', ['role' => 'buyer'])</div>
            @else
                <div class="thread-empty"><span class="empty-chat-icon" aria-hidden="true">&hellip;</span><h2>Your conversations, all in one place</h2><p>Choose a conversation to read messages and chat with a seller.</p></div>
            @endif
        </section>
    </div>
</section>
<script>
(() => {
    const root = document.getElementById('buyer-messaging');
    try {
        const key = @json('buyer.chat.' . auth('buyer')->id());
        const conversationId = root.dataset.conversationId;
        if (/^[1-9]\d*$/.test(conversationId)) {
            sessionStorage.setItem(key + '.conversation', conversationId);
            if (root.dataset.chatWidget !== 'true') sessionStorage.setItem(key + '.state', 'open');
        } else {
            sessionStorage.removeItem(key + '.conversation');
        }
    } catch (_) {}
    if (root.dataset.chatWidget === 'true') {
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && event.target?.type !== 'file' && !event.defaultPrevented) {
                event.preventDefault();
                if (window.parent !== window) window.parent.document.dispatchEvent(new Event('buyer-chat-close'));
            }
        });
    }
    const search = root.querySelector('#conversationSearch');
    const buttons = root.querySelectorAll('[data-filter]');
    const rows = [...root.querySelectorAll('[data-conversation]')];
    let filter = 'all';
    function applyFilter() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const matches = row.textContent.toLocaleLowerCase().includes(query)
                && (filter !== 'unread' || Number(row.dataset.unread) > 0)
                && (filter !== 'orders' || row.dataset.order === '1');
            row.hidden = !matches;
            if (matches) visible++;
        });
        root.querySelector('#noConversations').hidden = visible > 0 || rows.length === 0;
    }
    search?.addEventListener('input', applyFilter);
    buttons.forEach(button => button.addEventListener('click', () => {
        filter = button.dataset.filter;
        buttons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        applyFilter();
    }));
})();
</script>

@extends('layouts.seller')

@section('title', 'Messages')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 h-screen overflow-hidden p-4">
    <!-- Conversations List (Left Pane) -->
    <div class="md:col-span-1 bg-white rounded-lg shadow overflow-hidden flex flex-col">
        <div class="p-4 border-b">
            <h2 class="text-lg font-bold text-gray-900">Messages</h2>
        </div>

        <div id="conversationsList" class="flex-1 overflow-y-auto">
            @forelse($conversations as $conversation)
                <div class="border-b last:border-b-0 hover:bg-gray-50 transition cursor-pointer conversation-item" data-conversation-id="{{ $conversation->id }}">
                    <a href="{{ route('seller.messages.show', $conversation) }}" class="block p-4">
                        <div class="flex justify-between items-start mb-1">
                            <h3 class="font-semibold text-gray-900 flex-1">
                                {{ $conversation->buyer->name ?? 'Buyer' }}
                            </h3>
                            @if(($unreadCounts[$conversation->id] ?? 0) > 0)
                                <span class="bg-blue-500 text-white text-xs rounded-full px-2 py-1 ml-2">
                                    {{ $unreadCounts[$conversation->id] }}
                                </span>
                            @endif
                        </div>
                        @if($conversation->product)
                            <p class="text-xs text-gray-500 mb-2">
                                {{ Str::limit($conversation->product->name, 40) }}
                            </p>
                        @elseif($conversation->order)
                            <p class="text-xs text-gray-500 mb-2">
                                Order #{{ $conversation->order->id }}
                            </p>
                        @endif
                        <p class="text-sm text-gray-600 line-clamp-2">
                            {{ $conversation->messages->last()?->body ?? 'No messages' }}
                        </p>
                        <p class="text-xs text-gray-400 mt-1">
                            {{ $conversation->last_message_at?->format('M j, g:i a') }}
                        </p>
                    </a>
                </div>
            @empty
                <div class="p-4 text-center text-gray-400">
                    <p>No conversations yet.</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Message Thread (Right Pane) -->
    <div class="hidden md:flex md:col-span-2 flex-col bg-white rounded-lg shadow">
        <div id="threadContainer" class="flex-1 flex flex-col">
            <!-- This will be populated by JavaScript or load a selected conversation -->
            <div class="flex items-center justify-center h-full text-gray-400 p-4">
                <p>Select a conversation to view messages</p>
            </div>
        </div>
    </div>

    <!-- Mobile Conversation Detail (Stack Below on Mobile) -->
    <div id="mobileThreadContainer" class="md:hidden col-span-1 bg-white rounded-lg shadow hidden flex-col">
        <!-- Messages will load here on mobile -->
    </div>
</div>

<script>
    let currentConversationId = null;
    let pollInterval;
    const conversationItems = document.querySelectorAll('.conversation-item');

    // Load conversation thread
    async function loadConversationThread(conversationId) {
        currentConversationId = conversationId;
        
        try {
            const response = await fetch(`/seller/messages/${conversationId}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (response.ok) {
                const html = await response.text();
                // Update thread container with new content
                const threadContainer = document.getElementById('threadContainer');
                threadContainer.innerHTML = html;
                
                // Mark messages as read
                await markAsRead(conversationId);
            }
        } catch (error) {
            console.error('Error loading conversation:', error);
        }
    }

    async function markAsRead(conversationId) {
        try {
            await fetch(`/seller/messages/${conversationId}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]')?.value || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
        } catch (error) {
            console.error('Error marking as read:', error);
        }
    }

    // Add click handlers to conversation items
    conversationItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const conversationId = item.dataset.conversationId;
            loadConversationThread(conversationId);
        });
    });

    function startPolling() {
        if (pollInterval) return;
        
        pollInterval = setInterval(async () => {
            if (currentConversationId) {
                loadConversationThread(currentConversationId);
            }
        }, 5000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    // Pause polling when tab is hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    startPolling();
    window.addEventListener('beforeunload', stopPolling);
</script>
@endsection

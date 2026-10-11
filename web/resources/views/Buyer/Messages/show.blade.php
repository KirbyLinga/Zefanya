@extends('layouts.buyer')

@section('title', 'Message with ' . ($conversation->seller->name ?? 'Seller'))

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow mb-4 p-4 border-b">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">
                        {{ $conversation->seller->name ?? 'Seller' }}
                    </h2>
                    @if($conversation->product)
                        <p class="text-sm text-gray-500">
                            {{ Str::limit($conversation->product->name, 60) }}
                        </p>
                    @elseif($conversation->order)
                        <p class="text-sm text-gray-500">
                            Order #{{ $conversation->order->id }}
                        </p>
                    @endif
                </div>
                <a href="{{ route('buyer.messages.index') }}" class="text-blue-500 hover:text-blue-700">
                    ← Back
                </a>
            </div>
        </div>

        <!-- Messages Container -->
        <div id="messagesContainer" class="bg-white rounded-lg shadow mb-4 p-4 h-96 overflow-y-auto flex flex-col">
            @forelse($messages as $message)
                <div class="mb-4 {{ $message->sender_role === 'buyer' ? 'text-right' : 'text-left' }}">
                    <div class="inline-block max-w-xs bg-{{ $message->sender_role === 'buyer' ? 'blue' : 'gray' }}-100 rounded-lg p-3">
                        <p class="text-gray-900 break-words">
                            {!! htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8') !!}
                        </p>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $message->created_at->format('g:i a') }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex items-center justify-center h-full text-gray-400">
                    <p>No messages yet. Start the conversation!</p>
                </div>
            @endforelse
        </div>

        <!-- Message Input Form -->
        <form id="messageForm" class="bg-white rounded-lg shadow p-4">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $conversation->id }}">
            
            <div class="flex gap-2">
                <textarea
                    id="messageInput"
                    name="body"
                    class="flex-1 border border-gray-300 rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Type your message... (Shift+Enter for newline)"
                    rows="3"
                    required
                    maxlength="1000"
                ></textarea>
                <button
                    type="submit"
                    class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg transition h-fit"
                >
                    Send
                </button>
            </div>
            <div class="text-xs text-gray-500 mt-2">
                <span id="charCount">0</span>/1000 characters
            </div>
        </form>
    </div>
</div>

<script>
    const messagesContainer = document.getElementById('messagesContainer');
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    let pollInterval;

    // Update character count
    messageInput.addEventListener('input', () => {
        charCount.textContent = messageInput.value.length;
    });

    // Handle Enter/Shift+Enter
    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            messageForm.dispatchEvent(new Event('submit'));
        }
    });

    // Submit form via AJAX
    messageForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(messageForm);
        const body = formData.get('body').trim();

        if (!body) return;

        try {
            const response = await fetch('{{ route("buyer.messages.store") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json',
                },
                body: formData,
            });

            if (response.ok) {
                messageInput.value = '';
                charCount.textContent = '0';
                // Reload messages
                loadMessages();
            } else {
                alert('Failed to send message');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Error sending message');
        }
    });

    async function loadMessages() {
        try {
            const response = await fetch('{{ route("buyer.messages.show", $conversation) }}', {
                headers: { 'Accept': 'application/json' },
            });

            if (response.ok) {
                const data = await response.json();
                // Update messages (this is simplified; in production, use a proper templating system)
                location.reload(); // Simple refresh for now
            }
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    function startPolling() {
        if (pollInterval) return;
        
        pollInterval = setInterval(loadMessages, 5000);
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

    // Start polling on load
    startPolling();

    // Mark messages as read on page load
    async function markAsRead() {
        try {
            await fetch('{{ route("buyer.messages.show", $conversation) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
        } catch (error) {
            console.error('Error marking messages as read:', error);
        }
    }

    markAsRead();

    // Scroll to bottom
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    scrollToBottom();
    window.addEventListener('beforeunload', stopPolling);
</script>
@endsection

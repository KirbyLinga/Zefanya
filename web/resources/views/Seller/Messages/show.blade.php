<!-- Message Thread for Seller -->
<div class="flex flex-col h-full">
    <!-- Header -->
    <div class="p-4 border-b flex items-center justify-between">
        <div>
            <h3 class="font-bold text-gray-900">
                {{ $conversation->buyer->name ?? 'Buyer' }}
            </h3>
            @if($conversation->product)
                <p class="text-xs text-gray-500">
                    {{ Str::limit($conversation->product->name, 50) }}
                </p>
            @elseif($conversation->order)
                <p class="text-xs text-gray-500">
                    Order #{{ $conversation->order->id }}
                </p>
            @endif
        </div>
    </div>

    <!-- Messages -->
    <div id="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
        @forelse($messages as $message)
            <div class="flex {{ $message->sender_role === 'seller' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-xs bg-{{ $message->sender_role === 'seller' ? 'blue' : 'gray' }}-100 rounded-lg p-3">
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

    <!-- Input Form -->
    <div class="p-4 border-t bg-gray-50">
        <form id="messageForm" class="space-y-2">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $conversation->id }}">
            
            <textarea
                id="messageInput"
                name="body"
                class="w-full border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                placeholder="Type your message... (Shift+Enter for newline)"
                rows="2"
                required
                maxlength="1000"
            ></textarea>
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">
                    <span id="charCount">0</span>/1000
                </span>
                <button
                    type="submit"
                    class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded text-sm transition"
                >
                    Send
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const messageForm = document.getElementById('messageForm');
    const messageInput = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    const messagesContainer = document.getElementById('messagesContainer');

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
            const response = await fetch('{{ route("seller.messages.store") }}', {
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
                // Reload the conversation thread
                const conversationId = document.querySelector('input[name="conversation_id"]').value;
                // This will be reloaded by parent polling or we can refresh here
                location.reload();
            } else {
                alert('Failed to send message');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Error sending message');
        }
    });

    // Scroll to bottom
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    scrollToBottom();
</script>

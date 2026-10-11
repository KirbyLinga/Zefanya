@extends('layouts.buyer')

@section('title', 'Messages')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Messages</h1>

        @if($conversations->isEmpty())
            <div class="bg-white rounded-lg shadow p-8 text-center">
                <p class="text-gray-500 mb-4">No messages yet.</p>
                <p class="text-sm text-gray-400">Start a conversation with a seller to begin messaging.</p>
            </div>
        @else
            <div class="bg-white rounded-lg shadow overflow-hidden">
                @foreach($conversations as $conversation)
                    <div class="border-b last:border-b-0 hover:bg-gray-50 transition">
                        <a href="{{ route('buyer.messages.show', $conversation) }}" class="block p-4">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h3 class="font-semibold text-gray-900">
                                        {{ $conversation->seller->name ?? 'Seller' }}
                                    </h3>
                                    @if($conversation->product)
                                        <p class="text-sm text-gray-500">
                                            {{ Str::limit($conversation->product->name, 50) }}
                                        </p>
                                    @elseif($conversation->order)
                                        <p class="text-sm text-gray-500">
                                            Order #{{ $conversation->order->id }}
                                        </p>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-400">
                                    {{ $conversation->last_message_at?->format('M j, g:i a') }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 line-clamp-2">
                                {{ $conversation->messages->last()?->body ?? 'No messages' }}
                            </p>
                            @if($unreadCounts[$conversation->id] ?? 0)
                                <div class="mt-2">
                                    <span class="inline-block bg-blue-500 text-white text-xs rounded-full px-2 py-1">
                                        {{ $unreadCounts[$conversation->id] }} new
                                    </span>
                                </div>
                            @endif
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<script>
    // Poll for new messages every 5 seconds
    let pollInterval;

    function startPolling() {
        if (pollInterval) return;
        
        pollInterval = setInterval(async () => {
            try {
                const response = await fetch('{{ route("buyer.messages.index") }}');
                if (response.ok) {
                    const html = await response.text();
                    // Simple polling - refresh full page periodically
                    // In production, this could be replaced with a more sophisticated update mechanism
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        }, 5000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    // Pause polling when tab is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
        }
    });

    // Start polling on page load
    startPolling();

    // Clean up on page unload
    window.addEventListener('beforeunload', stopPolling);
</script>
@endsection

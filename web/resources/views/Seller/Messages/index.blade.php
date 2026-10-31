@extends('Layouts.seller')

@section('title', 'Messages')

@section('content')
<div class="bg-white rounded-lg shadow overflow-hidden flex flex-col">
    <div class="p-4 border-b">
        <h2 class="text-lg font-bold text-gray-900">Messages</h2>
    </div>

    <div id="conversationsList" class="flex-1 overflow-y-auto">
        @forelse($conversations as $conversation)
            @php($lastMessage = $conversation->messages->last())
            <div class="border-b last:border-b-0 hover:bg-gray-50 transition">
                <a href="{{ route('seller.messages.show', $conversation) }}" class="block p-4">
                    <div class="flex justify-between items-start mb-1">
                        <h3 class="font-semibold text-gray-900 flex-1">
                            {{ $conversation->buyer?->fullName() ?? 'Buyer' }}
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
                        {{ $lastMessage?->preview() ?? 'No messages' }}
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
@endsection

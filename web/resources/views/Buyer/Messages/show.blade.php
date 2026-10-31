@extends('Buyer.Layouts.app')

@section('title', 'Message with ' . ($conversation->seller->business_name ?? 'Seller'))

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow mb-4 p-4 border-b">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">
                        {{ $conversation->seller->business_name ?? 'Seller' }}
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
                <a href="{{ route('buyer.messages.index') }}" class="text-sm font-semibold text-[var(--brand)]">
                    ← Back
                </a>
            </div>
        </div>

        @include('Messages._product-card')

        <!-- Messages Container -->
        <div id="messagesContainer" class="bg-white rounded-lg shadow mb-4 p-4 h-96 overflow-y-auto space-y-3">
            @forelse($messages as $message)
                @include('Messages._bubble', ['role' => 'buyer'])
            @empty
                <div id="emptyThread" class="flex items-center justify-center h-full text-gray-400">
                    <p>No messages yet. Start the conversation!</p>
                </div>
            @endforelse
        </div>

        <!-- Message Input Form -->
        <div class="bg-white rounded-lg shadow p-4">
            @include('Messages._composer', ['role' => 'buyer'])
        </div>
    </div>
</div>
@endsection

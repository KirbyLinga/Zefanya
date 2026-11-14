{{-- One chat message. $role is the viewer ('buyer'|'seller'). Also rendered for the polling response. --}}
@php($mine = $message->sender_role === $role)
@if($role === 'buyer')
<div class="chat-message {{ $mine ? 'mine' : '' }}" data-message-id="{{ $message->id }}">
    @unless($mine)<span class="seller-avatar" aria-hidden="true">{{ mb_substr($conversation->seller->business_name ?? 'Seller', 0, 1) }}</span>@endunless
    <div class="chat-message-content">
        <div class="chat-bubble">
            @if($message->image_path)
                <a href="{{ $message->imageUrl($role) }}" target="_blank" rel="noopener"><img src="{{ $message->imageUrl($role) }}" alt="Photo sent in chat"></a>
            @endif
            @if($message->body !== '')<p>{{ $message->body }}</p>@endif
        </div>
        <p class="chat-time"><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('g:i A') }}</time></p>
    </div>
</div>
@else
<div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}" data-message-id="{{ $message->id }}">
    <div class="max-w-xs rounded-lg p-3 {{ $mine ? 'bg-primary-100' : 'bg-gray-100' }}">
        @if($message->image_path)
            <a href="{{ $message->imageUrl($role) }}" target="_blank" rel="noopener">
                <img src="{{ $message->imageUrl($role) }}" alt="Photo sent in chat" class="mb-1 max-h-60 rounded">
            </a>
        @endif
        @if($message->body !== '')
            <p class="text-gray-900 break-words whitespace-pre-line">{{ $message->body }}</p>
        @endif
        <p class="text-xs text-gray-500 mt-1">
            {{ $message->created_at->format('g:i a') }}
        </p>
    </div>
</div>

@endif

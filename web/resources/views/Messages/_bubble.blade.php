{{-- One chat message. $role is the viewer ('buyer'|'seller'). Also rendered for the polling response. --}}
@php($mine = $message->sender_role === $role)
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

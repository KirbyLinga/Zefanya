<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buyer ↔ Seller messages for the mobile app. Same ConversationPolicy, the
 * same StoreChatMessageRequest rules and Conversation::open() context checks
 * as Buyer\MessageController. Photos stay on the private disk and are served
 * through a bearer-authorized route.
 */
class MessageController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $conversations = $buyer->conversations()->with(['seller', 'product', 'order'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_role', 'seller')->whereNull('read_at')])
            ->orderBy('last_message_at', 'desc')
            ->get();

        return $this->success('Conversations retrieved.', $conversations->map(fn (Conversation $c): array => [
            'id' => $c->id,
            'seller' => ['id' => $c->seller_id, 'name' => $c->seller?->business_name],
            'product_id' => $c->product_id,
            'order_id' => $c->order_id,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ])->values());
    }

    public function open(Request $request): JsonResponse
    {
        $buyer = $request->user();
        $sellerId = $request->integer('seller_id');
        $data = $request->validate([
            'seller_id' => ['required', 'integer', Rule::exists('sellers', 'id')->where('status', 'approved')],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('seller_id', $sellerId)],
            'order_id' => [
                'nullable',
                'integer',
                Rule::exists('orders', 'id')->where('buyer_id', $buyer->id),
                Rule::exists('seller_orders', 'order_id')->where('seller_id', $sellerId),
            ],
        ]);

        $conversation = Conversation::open((int) $buyer->id, (int) $data['seller_id'], productId: $data['product_id'] ?? null, orderId: $data['order_id'] ?? null);

        return $this->success('Conversation ready.', ['conversation_id' => $conversation->id]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeView($request, $conversation);

        $messages = $conversation->messages()->where('id', '>', $request->integer('after'))->orderBy('id')->get();
        $messages->where('sender_role', 'seller')->where('read_at', null)->each(fn (Message $m) => $m->markAsRead());

        return $this->success('Messages retrieved.', [
            'id' => $conversation->id,
            'seller_name' => $conversation->seller?->business_name,
            'messages' => $messages->map(fn (Message $m) => $this->present($conversation, $m))->values(),
        ]);
    }

    public function store(StoreChatMessageRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();
        $conversation = Conversation::findOrFail($data['conversation_id']);
        $this->authorizeView($request, $conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'buyer',
            'sender_id' => $buyer->id,
            'body' => $data['body'] ?? '',
            'image_path' => $request->file('image')?->store('chat-images', 'local'),
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $this->success('Message sent.', $this->present($conversation, $message), 201);
    }

    public function image(Request $request, Conversation $conversation, Message $message): StreamedResponse
    {
        $this->authorizeView($request, $conversation);
        abort_if((int) $message->conversation_id !== (int) $conversation->id || $message->image_path === null, 404);

        return Storage::disk('local')->response($message->image_path);
    }

    private function authorizeView(Request $request, Conversation $conversation): void
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $conversation), 403, 'You are not part of this conversation.');
    }

    /** @return array<string, mixed> */
    private function present(Conversation $conversation, Message $m): array
    {
        return [
            'id' => $m->id,
            'sender_role' => $m->sender_role,
            'body' => $m->body,
            'image_url' => $m->image_path === null ? null : route('api.buyer.messages.image', [$conversation, $m]),
            'created_at' => $m->created_at?->toIso8601String(),
            'read_at' => $m->read_at?->toIso8601String(),
        ];
    }
}

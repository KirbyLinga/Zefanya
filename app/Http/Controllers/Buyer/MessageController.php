<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $buyer = Auth::guard('buyer')->user();
        $conversations = $buyer->conversations()
            ->with(['seller', 'product', 'order', 'messages'])
            ->orderBy('last_message_at', 'desc')
            ->get();

        // Calculate unread counts per conversation
        $unreadCounts = [];
        foreach ($conversations as $conversation) {
            $unreadCounts[$conversation->id] = $conversation->messages()
                ->where('sender_role', 'seller')
                ->where('read_at', null)
                ->count();
        }

        return view('Buyer.Messages.index', compact('conversations', 'unreadCounts'));
    }

    public function show(Conversation $conversation)
    {
        $buyer = Auth::guard('buyer')->user();

        if (! Gate::forUser($buyer)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // ?after= lets the open thread poll for only what it has not shown yet.
        $messages = $conversation->messages()
            ->where('id', '>', request()->integer('after'))
            ->orderBy('id')
            ->get();

        // Mark seller's messages as read
        $messages->where('sender_role', 'seller')
            ->where('read_at', null)
            ->each(fn (Message $m) => $m->markAsRead());

        // Return JSON for AJAX requests
        if (request()->wantsJson()) {
            return response()->json([
                'id' => $conversation->id,
                'buyer_name' => $conversation->buyer->fullName(),
                'seller_name' => $conversation->seller->business_name,
                'messages' => $messages->map(fn (Message $m) => [
                    'id' => $m->id,
                    'sender_role' => $m->sender_role,
                    'sender_name' => $m->sender_role === 'buyer' ? $conversation->buyer->fullName() : $conversation->seller->business_name,
                    'body' => htmlspecialchars($m->body, ENT_QUOTES, 'UTF-8'),
                    'image_url' => $m->imageUrl('buyer'),
                    'html' => view('Messages._bubble', ['message' => $m, 'role' => 'buyer'])->render(),
                    'created_at' => $m->created_at->format('M d, Y H:i'),
                    'read_at' => $m->read_at,
                ]),
            ]);
        }

        return view('Buyer.Messages.show', compact('conversation', 'messages'));
    }

    public function store(StoreChatMessageRequest $request): JsonResponse
    {
        $buyer = Auth::guard('buyer')->user();
        $validated = $request->validated();

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (! Gate::forUser($buyer)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'buyer',
            'sender_id' => $buyer->id,
            'body' => $validated['body'] ?? '',
            'image_path' => $request->file('image')?->store('chat-images', 'local'),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'id' => $message->id,
            'sender_role' => 'buyer',
            'sender_name' => $buyer->fullName(),
            'body' => htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8'),
            'image_url' => $message->imageUrl('buyer'),
            'created_at' => $message->created_at->format('M d, Y H:i'),
        ]);
    }

    /** Chat photos live on the private disk; only the conversation's buyer may fetch them here. */
    public function image(Conversation $conversation, Message $message): StreamedResponse
    {
        abort_unless(Gate::forUser(Auth::guard('buyer')->user())->allows('view', $conversation), 403);
        abort_if((int) $message->conversation_id !== (int) $conversation->id || $message->image_path === null, 404);

        return Storage::disk('local')->response($message->image_path);
    }

    public function getOrCreateConversation(Request $request): JsonResponse
    {
        $buyer = Auth::guard('buyer')->user();
        $sellerId = $request->integer('seller_id');

        $validated = $request->validate([
            'seller_id' => ['required', 'integer', Rule::exists('sellers', 'id')->where('status', 'approved')],
            // Context must really connect this buyer and seller.
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('seller_id', $sellerId)],
            'order_id' => [
                'nullable',
                'integer',
                Rule::exists('orders', 'id')->where('buyer_id', $buyer->id),
                Rule::exists('seller_orders', 'order_id')->where('seller_id', $sellerId),
            ],
        ]);

        $conversation = Conversation::open(
            (int) $buyer->id,
            (int) $validated['seller_id'],
            productId: $validated['product_id'] ?? null,
            orderId: $validated['order_id'] ?? null,
        );

        return response()->json(['conversation_id' => $conversation->id]);
    }

    public function unreadCount(): JsonResponse
    {
        $buyer = Auth::guard('buyer')->user();
        $count = Message::whereIn('conversation_id', $buyer->conversations()->pluck('id'))
            ->where('sender_role', 'seller')
            ->where('read_at', null)
            ->count();

        return response()->json(['unread_count' => $count]);
    }
}

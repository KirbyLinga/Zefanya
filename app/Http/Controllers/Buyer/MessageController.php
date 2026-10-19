<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

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

        $messages = $conversation->messages()->get();

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
                    'created_at' => $m->created_at->format('M d, Y H:i'),
                    'read_at' => $m->read_at,
                ]),
            ]);
        }

        return view('Buyer.Messages.show', compact('conversation', 'messages'));
    }

    public function store(Request $request): JsonResponse
    {
        $buyer = Auth::guard('buyer')->user();
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations',
            'body' => 'required|string|max:1000',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (! Gate::forUser($buyer)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'buyer',
            'sender_id' => $buyer->id,
            'body' => $validated['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'id' => $message->id,
            'sender_role' => 'buyer',
            'sender_name' => $buyer->fullName(),
            'body' => htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8'),
            'created_at' => $message->created_at->format('M d, Y H:i'),
        ]);
    }

    public function getOrCreateConversation(Request $request): JsonResponse
    {
        $buyer = Auth::guard('buyer')->user();
        $validated = $request->validate([
            'seller_id' => 'required|exists:sellers',
            'product_id' => 'nullable|exists:products',
            'order_id' => 'nullable|exists:orders',
        ]);

        $conversation = Conversation::firstOrCreate(
            [
                'buyer_id' => $buyer->id,
                'seller_id' => $validated['seller_id'],
            ],
            [
                'product_id' => $validated['product_id'] ?? null,
                'order_id' => $validated['order_id'] ?? null,
            ]
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

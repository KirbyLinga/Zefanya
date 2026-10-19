<?php

namespace App\Http\Controllers\Seller;

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
        $seller = Auth::guard('seller')->user();
        $conversations = $seller->conversations()
            ->with(['buyer', 'product', 'order', 'messages'])
            ->orderBy('last_message_at', 'desc')
            ->get();

        // Calculate unread counts per conversation
        $unreadCounts = [];
        foreach ($conversations as $conversation) {
            $unreadCounts[$conversation->id] = $conversation->messages()
                ->where('sender_role', 'buyer')
                ->where('read_at', null)
                ->count();
        }

        return view('Seller.Messages.index', compact('conversations', 'unreadCounts'));
    }

    public function show(Conversation $conversation)
    {
        $seller = Auth::guard('seller')->user();

        if (! Gate::forUser($seller)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messages = $conversation->messages()->get();

        // Mark buyer's messages as read
        $messages->where('sender_role', 'buyer')
            ->where('read_at', null)
            ->each(fn (Message $m) => $m->markAsRead());

        // Return JSON for AJAX requests
        if (request()->wantsJson()) {
            return response()->json([
                'id' => $conversation->id,
                'buyer_name' => $conversation->buyer->fullName(),
                'seller_name' => $conversation->seller->business_name,
                'product_id' => $conversation->product_id,
                'order_id' => $conversation->order_id,
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

        return view('Seller.Messages.show', compact('conversation', 'messages'));
    }

    public function store(Request $request): JsonResponse
    {
        $seller = Auth::guard('seller')->user();
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations',
            'body' => 'required|string|max:1000',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (! Gate::forUser($seller)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'seller',
            'sender_id' => $seller->id,
            'body' => $validated['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'id' => $message->id,
            'sender_role' => 'seller',
            'sender_name' => $seller->business_name,
            'body' => htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8'),
            'created_at' => $message->created_at->format('M d, Y H:i'),
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $seller = Auth::guard('seller')->user();
        $count = Message::whereIn('conversation_id', $seller->conversations()->pluck('id'))
            ->where('sender_role', 'buyer')
            ->where('read_at', null)
            ->count();

        return response()->json(['unread_count' => $count]);
    }
}

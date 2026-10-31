<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // ?after= lets the open thread poll for only what it has not shown yet.
        $messages = $conversation->messages()
            ->where('id', '>', request()->integer('after'))
            ->orderBy('id')
            ->get();

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
                    'image_url' => $m->imageUrl('seller'),
                    'html' => view('Messages._bubble', ['message' => $m, 'role' => 'seller'])->render(),
                    'created_at' => $m->created_at->format('M d, Y H:i'),
                    'read_at' => $m->read_at,
                ]),
            ]);
        }

        return view('Seller.Messages.show', compact('conversation', 'messages'));
    }

    public function store(StoreChatMessageRequest $request): JsonResponse
    {
        $seller = Auth::guard('seller')->user();
        $validated = $request->validated();

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if (! Gate::forUser($seller)->allows('view', $conversation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_role' => 'seller',
            'sender_id' => $seller->id,
            'body' => $validated['body'] ?? '',
            'image_path' => $request->file('image')?->store('chat-images', 'local'),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json([
            'id' => $message->id,
            'sender_role' => 'seller',
            'sender_name' => $seller->business_name,
            'body' => htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8'),
            'image_url' => $message->imageUrl('seller'),
            'created_at' => $message->created_at->format('M d, Y H:i'),
        ]);
    }

    /** Open the thread with the buyer of one of this seller's orders. */
    public function startFromOrder(SellerOrder $sellerOrder): RedirectResponse
    {
        $seller = Auth::guard('seller')->user();
        $this->authorizeForUser($seller, 'view', $sellerOrder);

        $order = $sellerOrder->order;
        abort_if($order?->buyer_id === null, 404);

        $conversation = Conversation::open((int) $order->buyer_id, (int) $seller->id, orderId: (int) $order->id);

        return redirect()->route('seller.messages.show', $conversation);
    }

    /** Chat photos live on the private disk; only the conversation's seller may fetch them here. */
    public function image(Conversation $conversation, Message $message): StreamedResponse
    {
        abort_unless(Gate::forUser(Auth::guard('seller')->user())->allows('view', $conversation), 403);
        abort_if((int) $message->conversation_id !== (int) $conversation->id || $message->image_path === null, 404);

        return Storage::disk('local')->response($message->image_path);
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

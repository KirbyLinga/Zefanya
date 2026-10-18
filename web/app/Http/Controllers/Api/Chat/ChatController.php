<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Models\Buyer\Buyer;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Buyer ↔ contact messaging — two routes.
 *
 * ## Who can use this
 *
 * Only buyers, for now. A seller or courier replying is a later addition;
 * the schema and the key logic already support it, but the API surface
 * only has a buyer's token in the current auth model.
 *
 * ## Contact resolution
 *
 * The `{contact}` segment is a display name — a seller's business name or a
 * courier's full name. This matches how the mobile app currently identifies
 * contacts (see ChatRepository header: "the key is a display name today").
 * When conversations get stable ids, the route segment becomes `{conversation}`
 * and this lookup is replaced by a direct id bind.
 *
 * ## Route: GET /api/chat/{contact}
 *
 * Returns the thread between the buyer and this contact, oldest-first, up to
 * 100 messages. A 404 is returned when the contact name does not match any
 * seller or courier — "not found" is the right status because a name the app
 * does not recognise is genuinely missing data, not a permission problem.
 *
 * ## Route: POST /api/chat/{contact}
 *
 * Appends a message from the buyer to the thread. Returns 201 with the new
 * message. A 404 is returned when the contact does not exist (same rule as
 * GET).
 */
class ChatController extends Controller
{
    /**
     * GET /api/chat/{contact}
     *
     * Thread history for the buyer ↔ contact pair, oldest first.
     * Limited to the last 100 messages so the response stays snappy
     * when a thread is long — pagination can come later; a polling app
     * typically only needs the recent tail anyway.
     */
    public function index(Request $request, string $contact): AnonymousResourceCollection
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This endpoint is for buyer tokens only.',
        );

        $key = ChatMessage::keyBetweenBuyerAndContact($buyer, $contact);
        abort_if($key === null, 404, 'Contact not found.');

        $messages = ChatMessage::query()
            ->where('conversation_key', $key)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return ChatMessageResource::collection($messages);
    }

    /**
     * POST /api/chat/{contact}
     *
     * Sends a message from the buyer to the contact. Returns 201 with the
     * stored message.
     */
    public function store(Request $request, string $contact): JsonResponse
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This endpoint is for buyer tokens only.',
        );

        $key = ChatMessage::keyBetweenBuyerAndContact($buyer, $contact);
        abort_if($key === null, 404, 'Contact not found.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = ChatMessage::query()->create([
            'conversation_key' => $key,
            'sender_type'      => ChatMessage::shortType($buyer),
            'sender_id'        => $buyer->id,
            'body'             => $data['body'],
        ]);

        return (new ChatMessageResource($message->fresh()))
            ->response()
            ->setStatusCode(201);
    }
}

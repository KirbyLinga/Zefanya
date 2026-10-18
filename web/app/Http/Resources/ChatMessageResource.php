<?php

namespace App\Http\Resources;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One chat message, in the shape `ChatMessage` on the mobile side expects.
 *
 * ## Fields
 *
 * - `id`      — stable server id; lets the app de-duplicate on polling
 * - `body`    — the text
 * - `is_me`   — true when the requesting user sent the message; resolved
 *               from the request's authenticated user so the same row
 *               shows `is_me: true` for the sender and `false` for the
 *               recipient — one representation, two truths
 * - `sent_at` — ISO 8601 UTC; the app formats this for display
 *
 * @mixin ChatMessage
 */
class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'      => (string) $this->id,
            'body'    => (string) $this->body,
            // True when the requesting user is the sender. The comparison uses
            // shortType so a buyer asking and a seller asking get the right
            // answer from the same row.
            'is_me'   => $user !== null && $this->resource->isSentBy($user),
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

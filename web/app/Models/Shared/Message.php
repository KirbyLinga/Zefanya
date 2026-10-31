<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_role',
        'sender_id',
        'body',
        'image_path',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @param 'buyer'|'seller' $role The viewer; each role has its own authorized image route. */
    public function imageUrl(string $role): ?string
    {
        return $this->image_path
            ? route("{$role}.messages.image", [$this->conversation_id, $this->id])
            : null;
    }

    /** Inbox preview: a photo sent without a caption has an empty body. */
    public function preview(): string
    {
        return $this->body !== '' ? $this->body : 'Photo';
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}

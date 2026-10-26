<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time token that guards buyer checkout against double-submits.
 *
 * Issued when the checkout page renders (random token stored in the session
 * AND here), consumed inside the order DB transaction. The consumption is a
 * conditional UPDATE (used = 0 → 1), so exactly one of two racing requests
 * can ever win — the loser sees the token as already used and is bounced.
 */
class CheckoutToken extends Model
{
    protected $fillable = [
        'token',
        'buyer_id',
        'used',
        'expires_at',
    ];

    protected $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer\Buyer::class);
    }

    /**
     * Issue a fresh token for a buyer (and opportunistically garbage-collect
     * this buyer's stale ones — the table is ephemeral by design).
     */
    public static function issue(int $buyerId): self
    {
        static::query()
            ->where('buyer_id', $buyerId)
            ->where('expires_at', '<', now())
            ->delete();

        return static::query()->create([
            'token' => bin2hex(random_bytes(32)),
            'buyer_id' => $buyerId,
            'used' => false,
            'expires_at' => now()->addHours(6),
        ]);
    }

    /**
     * Atomically claim the token inside the caller's DB transaction.
     * Returns false when the token is unknown/expired/already used — the
     * signal that a concurrent request already placed the order.
     */
    public static function claim(int $buyerId, ?string $token): bool
    {
        if ($token === null || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return false;
        }

        $claimed = static::query()
            ->where('buyer_id', $buyerId)
            ->where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->update(['used' => true]);

        return $claimed === 1;
    }
}

<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message in a buyer ↔ contact thread.
 *
 * ## Conversation key format
 *
 * `{lower_type}:{id}:{lower_type}:{id}`, sorted lexicographically so both
 * sides of a pair produce the same key:
 *
 *   keyFor(buyer:3, seller:7)  → 'buyer:3:seller:7'
 *   keyFor(seller:7, buyer:3)  → 'buyer:3:seller:7'  (same)
 *
 * The short type strings ('buyer', 'seller', 'courier') match the app's
 * own role names, which makes the key readable in logs without decoding.
 *
 * ## Sender type strings
 *
 * Stored as 'buyer', 'seller', or 'courier' (not full class names) so a
 * renamed class does not silently break all history. The morphMap in
 * `AppServiceProvider` maps these short names back to Eloquent models.
 */
class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_key',
        'sender_type',
        'sender_id',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Key helpers ──────────────────────────────────────────────────────────

    /**
     * The short type name stored in `sender_type` / `conversation_key`.
     *
     * 'buyer'   → App\Models\Buyer\Buyer
     * 'seller'  → App\Models\Seller\Seller
     * 'courier' → App\Models\Logistics\LogisticsProvider
     */
    public static function shortType(object $participant): string
    {
        return match (true) {
            $participant instanceof Buyer               => 'buyer',
            $participant instanceof Seller              => 'seller',
            $participant instanceof LogisticsProvider   => 'courier',
            default                                     => throw new \InvalidArgumentException(
                'Unknown participant type: '.get_class($participant)
            ),
        };
    }

    /**
     * Builds the canonical conversation key for two participants.
     *
     * Sorted so `keyFor(buyer, seller) === keyFor(seller, buyer)`.
     */
    public static function keyFor(object $a, object $b): string
    {
        $partA = self::shortType($a).':'.$a->id;
        $partB = self::shortType($b).':'.$b->id;

        // Lexicographic sort: 'buyer:...' < 'courier:...' < 'seller:...'
        [$first, $second] = strcmp($partA, $partB) <= 0
            ? [$partA, $partB]
            : [$partB, $partA];

        return "{$first}:{$second}";
    }

    /**
     * The conversation key between the signed-in buyer and a named contact.
     *
     * The contact is identified by the `contact_key` parameter the mobile app
     * sends — today that is a display name, which is looked up against sellers'
     * `business_name` and couriers' full names. When conversations get stable
     * ids, this lookup is replaced by a direct id lookup.
     *
     * Returns null when no contact with that name is found — the controller
     * uses that to 404 rather than 403, because "not found" is the right
     * answer: the app would not send a name it did not see in the catalogue.
     */
    public static function keyBetweenBuyerAndContact(Buyer $buyer, string $contactName): ?string
    {
        // Try seller first, then courier. The display name is unique enough
        // within each role for the demo data; a production system would use ids.
        $seller = Seller::query()->where('business_name', $contactName)->first();
        if ($seller !== null) {
            return self::keyFor($buyer, $seller);
        }

        $courier = LogisticsProvider::query()
            ->whereRaw("(first_name || ' ' || last_name) = ?", [$contactName])
            ->orWhereRaw("(first_name || ' ' || middle_initial || '. ' || last_name) = ?", [$contactName])
            ->first();
        if ($courier !== null) {
            return self::keyFor($buyer, $courier);
        }

        return null;
    }

    /**
     * Whether the signed-in user is the sender of this message.
     */
    public function isSentBy(object $user): bool
    {
        return $this->sender_type === self::shortType($user)
            && (int) $this->sender_id === (int) $user->id;
    }
}

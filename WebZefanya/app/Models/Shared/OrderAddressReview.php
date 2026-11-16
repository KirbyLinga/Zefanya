<?php

namespace App\Models\Shared;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admin phone confirmation of a cross-region delivery address. Holds the shipment before pickup until confirmed. */
class OrderAddressReview extends Model
{
    public const STATUSES = [
        'pending' => 'Awaiting phone check',
        'confirmed' => 'Address confirmed',
        'not_confirmed' => 'Address not confirmed',
    ];

    public const CALL_OUTCOMES = [
        'reached_confirmed' => 'Reached the Buyer; address confirmed',
        'reached_incorrect' => 'Reached the Buyer; address is wrong or unclear',
        'no_answer' => 'No answer after trying',
        'wrong_number' => 'Number unreachable or not the Buyer',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public static function available(): bool
    {
        return RuntimeSchema::hasTable('order_address_reviews');
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }

    public function holdsShipment(): bool
    {
        return $this->status !== 'confirmed';
    }

    public function reference(): string
    {
        return 'AR-'.$this->id;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Address check';
    }

    public function regionLabel(string $region): string
    {
        return config('address_review.region_names.'.$region, 'Region '.$region);
    }
}

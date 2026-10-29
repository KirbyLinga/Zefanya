<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerAddress extends Model
{
    use HasFactory;

    protected $table = 'buyer_addresses';

    protected $fillable = [
        'buyer_id',
        'recipient',
        'phone',
        'label',
        'region',
        'region_code',
        'province',
        'province_code',
        'city',
        'city_code',
        'barangay',
        'barangay_code',
        'street',
        'postal_code',
        'latitude',
        'longitude',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * Mark this address as default and unmark all others for this buyer.
     */
    public function makeDefault(): void
    {
        static::where('buyer_id', $this->buyer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Produce an immutable snapshot suitable for orders.shipping_address.
     */
    public function toSnapshot(): array
    {
        return [
            'address_id' => $this->id,
            'recipient' => $this->recipient,
            'phone' => $this->phone,
            'label' => $this->label,
            'region' => $this->region,
            'province' => $this->province ?: ($this->region === 'National Capital Region (NCR)' ? 'Metro Manila' : ''),
            'city' => $this->city,
            'barangay' => $this->barangay,
            'street' => $this->street,
            'postal_code' => $this->postal_code,
            'summary' => $this->formattedSummary(),
        ];
    }

    public function formattedSummary(): string
    {
        $prov = $this->province ?: ($this->region === 'National Capital Region (NCR)' ? 'Metro Manila' : '');
        return implode(', ', array_filter([
            trim($this->street),
            trim($this->barangay),
            trim($this->city),
            trim($prov),
            trim((string) $this->postal_code),
        ]));
    }
}

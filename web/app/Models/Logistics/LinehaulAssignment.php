<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinehaulAssignment extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['loading_tokens'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'arrived_at' => 'datetime', 'declined_at' => 'datetime',
            'offer_version' => 'integer', 'trip_pay_minor' => 'integer', 'loading_tokens' => 'encrypted:array', 'seal_receipt' => 'array', 'takeover_resumed_at' => 'datetime'];
    }

    public function scopeCurrentOffer(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Support\RuntimeSchema::hasColumn('linehaul_assignments', 'declined_at') ? $query->whereNull('declined_at') : $query;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(LinehaulDriver::class, 'linehaul_driver_id');
    }

    public function latestTransfer(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LinehaulTransfer::class)->latestOfMany();
    }

    public function payout(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        $payout = $this->hasOne(LinehaulTripPayout::class);
        if (\App\Support\RuntimeSchema::hasTable('linehaul_trip_payout_corrections')) {
            $payout->whereDoesntHave('reversal');
        }

        return $payout;
    }

    public function payouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LinehaulTripPayout::class);
    }

    public function latestDelay(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LinehaulTripDelay::class)->latestOfMany();
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }
}

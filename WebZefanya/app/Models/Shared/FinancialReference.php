<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * An external payment reference used by one payout or refund record. Written
 * only by FinancialReferenceRegistry; a reversal releases it, never deletes it.
 */
class FinancialReference extends Model
{
    public const PAYOUT = 'payout';

    public const REFUND = 'refund';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'amount_minor' => 'integer', 'reuses_id' => 'integer', 'released_at' => 'datetime'];
    }
}

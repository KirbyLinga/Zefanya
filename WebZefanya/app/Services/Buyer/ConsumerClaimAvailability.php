<?php

namespace App\Services\Buyer;

use Illuminate\Support\Facades\Schema;

class ConsumerClaimAvailability
{
    public function enabled(): bool
    {
        return config('consumer-claims.enabled', false)
            && Schema::hasTable('consumer_claims') && Schema::hasTable('consumer_claim_updates');
    }

    public function requireAvailable(): void
    {
        abort_unless($this->enabled(), 404);
    }
}

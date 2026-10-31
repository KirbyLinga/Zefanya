<?php

namespace App\Services\Seller;

use App\Models\Seller\Seller;
use Illuminate\Support\Facades\DB;

class SellerVacationService
{
    public function set(Seller $seller, bool $enabled): void
    {
        DB::transaction(function () use ($seller, $enabled): void {
            // Checkout holds this same row until its inventory/order transaction commits.
            $current = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->isApproved(), 403);
            $current->forceFill(['is_on_vacation' => $enabled])->save();
        }, 3);
        $seller->refresh();
    }
}

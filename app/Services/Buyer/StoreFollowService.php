<?php

namespace App\Services\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\StoreFollow;
use App\Models\Seller\Seller;
use Illuminate\Support\Facades\DB;

class StoreFollowService
{
    public function toggle(Buyer $buyer, Seller $seller): bool
    {
        return DB::transaction(function () use ($buyer, $seller): bool {
            // Serialize toggles and re-check visibility under the store lock.
            $current = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->isApproved() && ! $current->isArchived(), 404);
            $follow = StoreFollow::query()->where('buyer_id', $buyer->id)->where('seller_id', $seller->id)->first();
            if ($follow !== null) {
                $follow->delete();

                return false;
            }

            StoreFollow::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);

            return true;
        }, 3);
    }
}

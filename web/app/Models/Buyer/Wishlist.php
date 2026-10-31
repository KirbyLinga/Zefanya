<?php

namespace App\Models\Buyer;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wishlist extends Model
{
    protected $fillable = ['buyer_id', 'product_id'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function product(): BelongsTo
    {
        // Saved products remain visible and removable after seller archiving.
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

<?php

namespace App\Models\Admin;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminProductRestriction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
        'product_id',
        'product_name',
        'seller_name',
        'reason',
        'previous_status',
        'new_status',
        'created_at',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

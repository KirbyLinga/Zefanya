<?php

namespace App\Models\Catalog;

use App\Models\Admin\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'admin_email',
        'product_id',
        'product_name',
        'seller_name',
        'decision',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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

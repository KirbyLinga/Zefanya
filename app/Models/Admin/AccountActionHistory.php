<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An audit snapshot of an Admin account status action or Seller registration decision. */
class AccountActionHistory extends Model
{
    public const UPDATED_AT = null;

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}

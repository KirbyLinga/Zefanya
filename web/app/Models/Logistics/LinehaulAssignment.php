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
        return ['accepted_at' => 'datetime', 'arrived_at' => 'datetime', 'loading_tokens' => 'encrypted:array'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(LinehaulDriver::class, 'linehaul_driver_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }
}

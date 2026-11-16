<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class AllocationPolicyApproval extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}

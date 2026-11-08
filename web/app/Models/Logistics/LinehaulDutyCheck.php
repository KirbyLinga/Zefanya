<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class LinehaulDutyCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'passed' => 'boolean'];
    }
}

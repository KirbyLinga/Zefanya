<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}

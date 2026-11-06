<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlatformSettingHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Setting history cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('Setting history cannot be deleted.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}

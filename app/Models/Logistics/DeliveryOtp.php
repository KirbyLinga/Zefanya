<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class DeliveryOtp extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return ['code_encrypted' => 'encrypted', 'expires_at' => 'datetime', 'sent_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}

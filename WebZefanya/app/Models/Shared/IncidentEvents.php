<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvents extends Model
{
    protected $table = 'incident_events';

    public $timestamps = false;

    protected $fillable = [
        'incident_id', 'event', 'label', 'detail', 'occurred_at',
        'resolved_by_admin_id', 'resolution_reason',
        'actor_type', 'actor_id', 'from_status', 'to_status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_by_admin_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incidents::class, 'incident_id');
    }
}

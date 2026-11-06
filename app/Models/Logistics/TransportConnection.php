<?php

namespace App\Models\Logistics;

use App\Services\Logistics\TransportConnectionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit hub-to-hub transport connection for multi-leg routing.
 *
 * Represents a direct transport path (ROAD, SEA, AIR) between two operational hubs,
 * operated by the origin hub provider. Cross-provider travel also requires an
 * approved directed partnership. Each connection must be active to be
 * considered during route planning.
 *
 * Do not infer connections from geography — only use explicitly configured connections.
 */
class TransportConnection extends Model
{
    use HasFactory;

    public const ROAD = 'ROAD';

    public const SEA = 'SEA';

    public const AIR = 'AIR';

    public const TRANSPORT_MODES = [self::ROAD, self::SEA, self::AIR];

    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'origin_hub_id',
        'destination_hub_id',
        'transport_mode',
        'operating_provider_id',
        'is_active',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            // Historical connections can always be disabled, even after hub/link suspension.
            if ($connection->exists && ! $connection->is_active
                && ! $connection->isDirty(['origin_hub_id', 'destination_hub_id', 'transport_mode', 'operating_provider_id'])) {
                return;
            }
            $connection->operating_provider_id ??= LogisticsHub::query()->find($connection->origin_hub_id)?->logistics_provider_id;
            app(TransportConnectionService::class)->validate($connection);
        });
    }

    public function isActive(): bool
    {
        return app(TransportConnectionService::class)->isUsable($this);
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_hub_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_hub_id');
    }

    public function operatingProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'operating_provider_id');
    }
}

<?php

namespace App\Models\Admin;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/** One sensitive operation: who, when, on what, the old and new values, and why. */
class OperationsAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'shipment_status_overridden' => 'Shipment status overridden',
        'rider_approved' => 'Rider approved',
        'rider_rejected' => 'Rider rejected',
        'rider_suspended' => 'Rider suspended',
        'rider_deactivated' => 'Rider deactivated',
        'rider_reactivated' => 'Rider reactivated',
        'rider_chosen_manually' => 'Rider chosen manually',
        'rider_reassigned' => 'Rider reassigned',
        'address_verified_manually' => 'Address verified manually',
        'remittance_confirmed' => 'Remittance confirmed',
        'rate_changed' => 'Shipping rate changed',
        'logistics_remittance_submitted' => 'Payment to Zefanya recorded',
        'logistics_remittance_confirmed' => 'Payment to Zefanya confirmed',
        'logistics_remittance_rejected' => 'Payment to Zefanya rejected',
        'seller_payout_paid' => 'Seller payout paid',
        'seller_payout_rejected' => 'Seller payout rejected',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Operations audit logs cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('Operations audit logs cannot be deleted.');
        });
    }

    /** Bulk queries skip model events, so they are refused here as well. */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Operations audit logs cannot be edited.');
            }

            public function delete()
            {
                throw new LogicException('Operations audit logs cannot be deleted.');
            }

            public function forceDelete()
            {
                throw new LogicException('Operations audit logs cannot be deleted.');
            }
        };
    }

    /** Call inside the action's transaction, so the action and its log commit together. */
    public static function record(LogisticsProvider|Admin $actor, string $action, Model $entity, string $entityLabel, ?array $old, ?array $new, ?string $reason = null): self
    {
        $isAdmin = $actor instanceof Admin;

        $log = new self;
        $log->forceFill([
            'actor_type' => $isAdmin ? 'admin' : 'logistics',
            'actor_id' => $actor->getKey(),
            'actor_name' => $isAdmin ? $actor->name : ($actor->business_name ?: $actor->fullName()),
            // An Admin decision still shows in the company's own Activity log.
            'logistics_provider_id' => $isAdmin ? $entity->getAttribute('logistics_provider_id') : $actor->getKey(),
            'action' => $action,
            'entity_type' => Str::singular($entity->getTable()),
            'entity_id' => $entity->getKey(),
            'entity_label' => $entityLabel,
            'old_values' => $old,
            'new_values' => $new,
            'reason' => filled($reason) ? trim($reason) : null,
        ])->save();

        return $log;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? Str::headline($this->action);
    }

    /**
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $readable = fn ($value): ?string => $value === null ? null : Str::ucfirst(str_replace('_', ' ', (string) $value));

        return collect(array_keys($old + $new))->map(fn (string $field): array => [
            'field' => Str::ucfirst(str_replace('_', ' ', $field)),
            'old' => $readable($old[$field] ?? null),
            'new' => $readable($new[$field] ?? null),
        ])->all();
    }
}

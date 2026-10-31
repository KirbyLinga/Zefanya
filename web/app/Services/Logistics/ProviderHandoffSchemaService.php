<?php

namespace App\Services\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cross-provider partnership lifecycle plus the Phase 5.1 handoff foundation.
 * No parcel, checkout, or manifest transition is performed here.
 */
class ProviderHandoffSchemaService
{
    public function __construct(private readonly LogisticsHubService $hubs = new LogisticsHubService) {}

    public function requestLink(LogisticsProvider $origin, LogisticsProvider $destination, int $priority = 0): LogisticsProviderLink
    {
        $link = new LogisticsProviderLink;
        $link->forceFill([
            'origin_provider_id' => $origin->getKey(),
            'destination_provider_id' => $destination->getKey(),
            'priority' => $priority,
            'origin_consented_by' => $origin->getKey(),
            'origin_consented_at' => now(),
        ])->save();

        return $link;
    }

    /**
     * Destination providers that currently qualify for a new request from this
     * origin. The request service repeats every eligibility check server-side.
     *
     * @return Collection<int, LogisticsProvider>
     */
    public function eligibleDestinations(LogisticsProvider $origin): Collection
    {
        return LogisticsProvider::query()
            ->whereKeyNot($origin->getKey())
            ->where('status', LogisticsProviderStatus::Approved->value)
            ->whereNull('archived_at')
            ->whereNotNull('province_verified_at')
            ->whereNotNull('province_code')
            ->whereDoesntHave('incomingLinks', fn ($query) => $query
                ->where('origin_provider_id', $origin->getKey())
                ->whereIn('status', [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE]))
            ->orderBy('business_name')
            ->get()
            ->filter(fn (LogisticsProvider $provider): bool => $this->hubs->hasOperationalProvinceCoverage($provider))
            ->values();
    }

    /**
     * Destination-only acceptance. Acceptance is consent; activation still
     * waits for the Admin approval.
     */
    public function accept(LogisticsProviderLink $link, LogisticsProvider $actor): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $actor): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->require((int) $actor->getKey() === (int) $locked->destination_provider_id, 'destination_provider_id', 'Only the destination provider can respond to this partnership request.');
            $this->require($locked->status === LogisticsProviderLink::PENDING, 'status', 'Only pending partnership requests can be accepted.');

            $locked->forceFill([
                'destination_consented_by' => $actor->getKey(),
                'destination_consented_at' => now(),
            ]);
            $this->activateWhenApproved($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Generic participant consent retained for the Phase 5.1 foundation.
     * New HTTP flows use {@see accept()} so only the destination can respond.
     */
    public function consent(LogisticsProviderLink $link, LogisticsProvider $actor): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $actor): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $side = match ((int) $actor->getKey()) {
                (int) $locked->origin_provider_id => 'origin',
                (int) $locked->destination_provider_id => 'destination',
                default => abort(403, 'Only the participating providers may consent.'),
            };
            $this->require($locked->status === LogisticsProviderLink::PENDING, 'status', 'Only pending links can receive consent.');

            $locked->forceFill([$side.'_consented_by' => $actor->getKey(), $side.'_consented_at' => now()]);
            $this->activateWhenApproved($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Destination-only rejection. A later request may be submitted if the
     * relationship is reconsidered; this row remains immutable history.
     */
    public function reject(LogisticsProviderLink $link, LogisticsProvider $actor, string $reason): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $actor, $reason): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->require((int) $actor->getKey() === (int) $locked->destination_provider_id, 'destination_provider_id', 'Only the destination provider can reject this partnership request.');
            $this->require($locked->status === LogisticsProviderLink::PENDING, 'status', 'Only pending partnership requests can be rejected.');
            $this->require(filled($reason) && mb_strlen($reason) <= 500, 'rejection_reason', 'Provide a rejection reason of 500 characters or fewer.');

            // A rejection supersedes an earlier acceptance that was still
            // waiting for Admin approval.
            $locked->forceFill([
                'status' => LogisticsProviderLink::REJECTED,
                'destination_consented_by' => null,
                'destination_consented_at' => null,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            return $locked;
        });
    }

    /**
     * Admin approval. Passing a priority sets/changes it as part of the
     * decision; omitting it keeps the request's priority.
     */
    public function approve(LogisticsProviderLink $link, Admin $actor, ?int $priority = null): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $actor, $priority): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->require($locked->status === LogisticsProviderLink::PENDING, 'status', 'Only pending partnership requests can be approved.');
            if ($priority !== null) {
                $this->requirePriority($priority);
            }

            $locked->forceFill([
                'priority' => $priority ?? $locked->priority,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);
            $this->activateWhenApproved($locked);
            $locked->save();

            return $locked;
        });
    }

    public function setPriority(LogisticsProviderLink $link, Admin $actor, int $priority): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $priority): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->require(in_array($locked->status, [LogisticsProviderLink::PENDING, LogisticsProviderLink::ACTIVE], true), 'status', 'Only pending or active partnerships can change priority.');
            $this->requirePriority($priority);

            $locked->forceFill(['priority' => $priority])->save();

            return $locked;
        });
    }

    /**
     * Admin revocation retains the original approval and both consents as
     * history while making the route no longer usable for new handoffs.
     */
    public function revoke(LogisticsProviderLink $link, Admin $actor, string $reason): LogisticsProviderLink
    {
        return DB::transaction(function () use ($link, $actor, $reason): LogisticsProviderLink {
            $locked = LogisticsProviderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->require($locked->status === LogisticsProviderLink::ACTIVE, 'status', 'Only active partnerships can be revoked.');
            $this->require(filled($reason) && mb_strlen($reason) <= 500, 'revocation_reason', 'Provide a revocation reason of 500 characters or fewer.');

            $activeHandoff = $locked->handoffs()
                ->whereIn('status', [
                    ParcelHandoffStatus::Requested->value,
                    ParcelHandoffStatus::Accepted->value,
                    ParcelHandoffStatus::InTransit->value,
                ])
                ->exists();
            $this->require(! $activeHandoff, 'status', 'This partnership has parcel handoffs in progress and cannot be revoked yet.');

            $locked->forceFill([
                'status' => LogisticsProviderLink::REVOKED,
                'revoked_by' => $actor->getKey(),
                'revoked_at' => now(),
                'revocation_reason' => trim($reason),
            ])->save();

            return $locked;
        });
    }

    private function activateWhenApproved(LogisticsProviderLink $link): void
    {
        if ($link->status === LogisticsProviderLink::PENDING && $link->origin_consented_at !== null
            && $link->destination_consented_at !== null && $link->approved_at !== null) {
            $link->status = LogisticsProviderLink::ACTIVE;
        }
    }

    public function validateLink(LogisticsProviderLink $link): void
    {
        $this->require(! $link->exists || ! $link->isDirty(['origin_provider_id', 'destination_provider_id']), 'link', 'Link direction is immutable.');
        $this->require((int) $link->origin_provider_id !== (int) $link->destination_provider_id, 'destination_provider_id', 'Self-links are not allowed.');
        $this->require(in_array($link->status, [
            LogisticsProviderLink::PENDING,
            LogisticsProviderLink::ACTIVE,
            LogisticsProviderLink::INACTIVE,
            LogisticsProviderLink::REJECTED,
            LogisticsProviderLink::REVOKED,
        ], true), 'status', 'Invalid link status.');
        $this->require($link->priority >= 0, 'priority', 'Priority must be non-negative.');

        // Existing terminal history remains saveable even after a provider
        // loses eligibility. New rows and live decisions are revalidated.
        $terminal = in_array($link->status, LogisticsProviderLink::TERMINAL, true);
        if (! $link->exists || ! $terminal) {
            foreach (['origin', 'destination'] as $side) {
                $this->eligibleProvider((int) $link->{$side.'_provider_id'});
            }
            $this->requireDestinationCoverage((int) $link->destination_provider_id);
        }

        foreach (['origin', 'destination'] as $side) {
            $actor = $link->{$side.'_consented_by'};
            $at = $link->{$side.'_consented_at'};
            $this->require(($actor === null) === ($at === null), $side.'_consented_at', 'Consent requires both actor and timestamp.');
            $this->require($actor === null || (int) $actor === (int) $link->{$side.'_provider_id'}, $side.'_consented_by', 'Consent must come from the corresponding provider.');
        }
        $this->require(($link->approved_by === null) === ($link->approved_at === null), 'approved_at', 'Approval requires an Admin and timestamp.');
        $this->require(($link->rejected_by === null) === ($link->rejected_at === null), 'rejected_at', 'Rejection requires a provider and timestamp.');
        $this->require($link->rejected_by === null || (int) $link->rejected_by === (int) $link->destination_provider_id, 'rejected_by', 'Only the destination provider can reject a partnership.');
        $this->require(($link->revoked_by === null) === ($link->revoked_at === null), 'revoked_at', 'Revocation requires an Admin and timestamp.');

        if ($link->status !== LogisticsProviderLink::REJECTED) {
            $this->require($link->rejected_by === null && $link->rejected_at === null && blank($link->rejection_reason), 'rejected_at', 'Rejection metadata requires a rejected link.');
        } else {
            $this->require($link->rejected_at !== null && filled($link->rejection_reason), 'rejection_reason', 'Rejected partnerships require a reason.');
            $this->require($link->destination_consented_at === null, 'destination_consented_at', 'A rejected partnership cannot retain destination consent.');
        }

        if ($link->status !== LogisticsProviderLink::REVOKED) {
            $this->require($link->revoked_by === null && $link->revoked_at === null && blank($link->revocation_reason), 'revoked_at', 'Revocation metadata requires a revoked link.');
        } else {
            $this->require($link->approved_at !== null && $link->revoked_at !== null && filled($link->revocation_reason), 'revocation_reason', 'Revoked partnerships require the original approval and a reason.');
        }

        if ($link->status === LogisticsProviderLink::ACTIVE) {
            $this->require($link->origin_consented_at !== null && $link->destination_consented_at !== null && $link->approved_at !== null, 'status', 'Active links require both providers\' consent and Admin approval.');
        }
    }

    public function validateHandoff(ParcelHandoff $handoff): void
    {
        $route = ['seller_order_id', 'logistics_provider_link_id', 'origin_provider_id', 'destination_provider_id', 'sending_hub_id', 'receiving_hub_id', 'direction'];
        $this->require(! $handoff->exists || ! $handoff->isDirty($route), 'handoff', 'Historical handoff relationships are immutable.');
        if (! $handoff->exists) {
            $parcel = $handoff->sellerOrder;
            $isReturn = $parcel?->hasActiveReturn() ?? false;
            $this->require(($handoff->direction === 'return') === $isReturn, 'direction', 'Return and outgoing custody movements cannot be mixed.');
            if ($isReturn) {
                $this->require((int) $handoff->destination_provider_id === (int) $parcel->parcelReturn->origin_provider_id
                    && (int) $handoff->receiving_hub_id === (int) $parcel->parcelReturn->origin_hub_id, 'handoff', 'Reverse custody must return directly to the original Seller provider and hub.');
            }

            $link = LogisticsProviderLink::query()->find($handoff->logistics_provider_link_id);
            $this->require($link !== null && $link->status === LogisticsProviderLink::ACTIVE, 'link', 'New handoffs require an active approved link.');
            $this->validateLink($link);
            $this->require((int) $link->origin_provider_id === (int) $handoff->origin_provider_id
                && (int) $link->destination_provider_id === (int) $handoff->destination_provider_id, 'link', 'Handoff providers must match the directed link.');
            foreach (['sending' => 'origin', 'receiving' => 'destination'] as $hubSide => $providerSide) {
                $hub = LogisticsHub::query()->find($handoff->{$hubSide.'_hub_id'});
                $this->require($hub !== null && $hub->isOperational()
                    && (int) $hub->logistics_provider_id === (int) $handoff->{$providerSide.'_provider_id'}, $hubSide.'_hub_id', 'Select an operational hub owned by the corresponding provider.');
            }
        }

        $events = ['requested' => 'origin', 'accepted' => 'destination', 'rejected' => 'destination', 'dispatched' => 'origin', 'received' => 'destination'];
        foreach ($events as $event => $side) {
            $at = $handoff->{$event.'_at'};
            $id = $handoff->{$event.'_by_id'};
            $type = $handoff->{$event.'_by_type'};
            $this->require(($at === null && $id === null && $type === null)
                || ($at !== null && $type === 'logistics' && (int) $id === (int) $handoff->{$side.'_provider_id'}), $event.'_by_id', 'Handoff event requires its participating provider and timestamp.');
        }
        $required = match ($handoff->status) {
            ParcelHandoffStatus::Requested => ['requested'],
            ParcelHandoffStatus::Accepted => ['requested', 'accepted'],
            ParcelHandoffStatus::Rejected => ['requested', 'rejected'],
            ParcelHandoffStatus::InTransit => ['requested', 'accepted', 'dispatched'],
            ParcelHandoffStatus::Received => ['requested', 'accepted', 'dispatched', 'received'],
        };
        foreach ($required as $event) {
            $this->require($handoff->{$event.'_at'} !== null, $event.'_at', 'The handoff status requires this audit event.');
        }
        $this->require($handoff->status !== ParcelHandoffStatus::Rejected || filled($handoff->rejection_reason), 'rejection_reason', 'Rejected handoffs require a reason.');
    }

    private function requirePriority(int $priority): void
    {
        $this->require($priority >= 0, 'priority', 'Priority must be non-negative.');
    }

    private function eligibleProvider(int $id): void
    {
        $provider = LogisticsProvider::query()->find($id);
        $this->require($provider !== null && $provider->isApproved() && $provider->hasVerifiedProvince()
            && $provider->archived_at === null, 'provider', 'Both providers must be approved, province-verified, and non-archived.');
    }

    private function requireDestinationCoverage(int $id): void
    {
        $provider = LogisticsProvider::query()->find($id);
        $this->require($provider !== null && $this->hubs->hasOperationalProvinceCoverage($provider),
            'destination_provider_id',
            'The destination provider must have active operational coverage in its verified province.');
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}

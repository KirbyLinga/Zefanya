<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\LostParcelFound;
use App\Notifications\Incident\ParcelLostConfirmed;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ParcelLossService
{
    public static function available(): bool
    {
        return Schema::hasTable('parcel_loss_cases') && Schema::hasTable('parcel_loss_adjustments');
    }

    public static function authorize(Admin $admin): void
    {
        abort_unless(in_array($admin->role, ['admin', 'super_admin'], true), 403);
    }

    public function caseFor(SellerOrder $parcel): ?ParcelLossCase
    {
        return self::available() ? ParcelLossCase::query()->where('seller_order_id', $parcel->id)->first() : null;
    }

    /** The incident resolver holds the parcel and incident locks. */
    public function finalize(SellerOrder $parcel, Incidents $incident, Admin $admin, string $kind, string $reason): ParcelLossCase
    {
        self::authorize($admin);
        abort_unless(self::available(), 503, 'Parcel-loss recording requires its database update.');
        $case = $this->caseFor($parcel);
        if ($case !== null) {
            return $case;
        }

        $before = $parcel->status;
        $return = $parcel->parcelReturn()->lockForUpdate()->first();
        $inReturn = $return?->inReturnNetwork() === true;
        $handoffs = ParcelHandoff::query()->where('seller_order_id', $parcel->id)->orderByDesc('id')->lockForUpdate()->get();
        $handoff = $handoffs->first(fn (ParcelHandoff $candidate): bool => $candidate->dispatched_at !== null);
        [$providerId, $basis] = $this->liability($parcel, $handoff);

        if ($inReturn) {
            app(ParcelReturnService::class)->closeAsLoss($return, $admin, $kind, $reason);
        }
        if (! $inReturn || ! in_array($before, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
            app(SellerOrderStatusService::class)->recordLoss($parcel, $admin, $reason);
        }

        $case = new ParcelLossCase;
        $case->forceFill([
            'seller_order_id' => $parcel->id,
            'seller_id' => $parcel->seller_id,
            'incident_id' => $incident->id,
            'parcel_return_id' => $inReturn ? $return->id : null,
            'logistics_provider_id' => $providerId,
            'confirmed_by' => $admin->id,
            'kind' => $kind,
            'liability_basis' => $basis,
            'merchandise_minor' => (int) $parcel->subtotal_minor,
            'compensation_status' => $providerId === null || ($inReturn && $before === OrderStatus::Delivered)
                ? ParcelLossCase::REVIEW_REQUIRED
                : ParcelLossCase::PENDING,
        ])->save();

        foreach ($handoffs as $candidate) {
            if ($candidate->isActive()) {
                $candidate->forceFill([
                    'status' => ParcelHandoffStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by_type' => 'admin',
                    'cancelled_by_id' => $admin->id,
                    'cancellation_reason' => $reason,
                ])->save();
            }
        }

        if ($kind === 'lost') {
            $batchIds = ManifestItem::query()->where('seller_order_id', $parcel->id)
                ->whereNull('received_at')->whereNull('released_at')->pluck('manifest_id');
            $batches = Manifest::query()->whereKey($batchIds->all())->where('status', ManifestStatus::Dispatched)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($batches as $batch) {
                $item = $batch->items()->where('seller_order_id', $parcel->id)
                    ->whereNull('received_at')->whereNull('released_at')->lockForUpdate()->first();
                $item?->markMissing($reason);
                app(ManifestService::class)->updateManifestStatusForExceptions($batch);
            }
        }

        OperationsAuditLog::record($admin, 'parcel_lost_confirmed', $case, $parcel->tracking_number ?? 'Parcel #'.$parcel->id,
            ['status' => $before->value], ['status' => $parcel->status->value, 'kind' => $kind], $reason);
        $parcel->loadMissing(['order.buyer', 'seller']);
        $parcel->order?->buyer?->notify(new ParcelLostConfirmed($case, 'buyer'));
        $parcel->seller?->notify(new ParcelLostConfirmed($case, 'seller'));
        $case->provider?->notify(new ParcelLostConfirmed($case, 'logistics'));

        return $case;
    }

    /** @return array{0: ?int, 1: string} */
    private function liability(SellerOrder $parcel, ?ParcelHandoff $handoff): array
    {
        if ($handoff?->status === ParcelHandoffStatus::InTransit && $handoff->received_at === null) {
            return [(int) $handoff->origin_provider_id, ParcelLossCase::BASIS_BEFORE_RECEIPT];
        }
        if ($parcel->custody_provider_id !== null) {
            return [(int) $parcel->custody_provider_id, ParcelLossCase::BASIS_AFTER_RECEIPT];
        }

        return [null, ParcelLossCase::BASIS_REVIEW];
    }

    public function confirmMany(array $ids, Admin $admin, string $reason): array
    {
        self::authorize($admin);
        $confirmed = [];
        $failed = [];
        foreach (Incidents::query()->whereKey($ids)->orderBy('id')->get() as $incident) {
            try {
                app(ParcelIncidentService::class)->resolve($incident, $admin, ['event' => 'CLOSE_LOST', 'reason' => $reason]);
                $confirmed[] = (int) $incident->incident_seller_order_id;
            } catch (ValidationException $error) {
                $failed[$incident->incident_seller_order_id] = collect($error->errors())->flatten()->first();
            }
        }

        return ['confirmed' => array_values(array_unique($confirmed)), 'failed' => $failed];
    }

    public function assignLiability(ParcelLossCase $case, Admin $admin, int $providerId, string $reason): ParcelLossCase
    {
        self::authorize($admin);

        return DB::transaction(function () use ($case, $admin, $providerId, $reason): ParcelLossCase {
            $locked = ParcelLossCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $provider = LogisticsProvider::query()->whereNull('archived_at')->find($providerId);
            if ($locked->compensation_status !== ParcelLossCase::REVIEW_REQUIRED || ! $provider?->isApproved() || trim($reason) === '') {
                throw ValidationException::withMessages(['provider' => 'Choose an approved Logistics company and record the reason for the liability decision.']);
            }
            $previous = $locked->logistics_provider_id;
            $locked->forceFill(['logistics_provider_id' => $providerId, 'liability_basis' => ParcelLossCase::BASIS_ADMIN,
                'compensation_status' => ParcelLossCase::PENDING])->save();
            OperationsAuditLog::record($admin, 'lost_compensation_assigned', $locked, 'Parcel #'.$locked->seller_order_id,
                ['logistics_provider_id' => $previous], ['logistics_provider_id' => $providerId], $reason);
            $provider->notify(new ParcelLostConfirmed($locked, 'logistics'));

            return $locked;
        }, 3);
    }

    public function recordCompensationPayment(ParcelLossCase $case, Admin $admin, string $reference, string $date): ParcelLossCase
    {
        self::authorize($admin);
        validator(['reference' => trim($reference), 'date' => $date], ['reference' => 'required|string|max:120', 'date' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();
        $reference = trim($reference);

        return DB::transaction(function () use ($case, $admin, $reference, $date): ParcelLossCase {
            $locked = ParcelLossCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPaid()) {
                if ($locked->compensation_reference !== $reference || $locked->compensation_paid_at->toDateString() !== $date) {
                    throw ValidationException::withMessages(['reference' => 'Compensation is already recorded as paid. Use an audited adjustment to record a correction.']);
                }

                return $locked;
            }
            if ($locked->compensation_status !== ParcelLossCase::PENDING || $locked->logistics_provider_id === null) {
                throw ValidationException::withMessages(['reference' => 'Decide which Logistics company is liable before recording a payment.']);
            }
            $locked->forceFill(['compensation_status' => ParcelLossCase::PAID, 'compensation_reference' => $reference,
                'compensation_paid_at' => $date])->save();
            OperationsAuditLog::record($admin, 'lost_compensation_paid', $locked, 'Parcel #'.$locked->seller_order_id,
                ['status' => ParcelLossCase::PENDING], ['status' => ParcelLossCase::PAID, 'reference' => $reference]);

            return $locked;
        }, 3);
    }

    public function recordAdjustment(ParcelLossCase $case, Admin $admin, int $amount, string $reason): void
    {
        self::authorize($admin);
        DB::transaction(function () use ($case, $admin, $amount, $reason): void {
            $locked = ParcelLossCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $net = $locked->netCompensationMinor();
            if (! $locked->isPaid() || $amount === 0 || $amount < -$net || $amount > PHP_INT_MAX - $net || trim($reason) === '') {
                throw ValidationException::withMessages(['amount' => 'A paid case requires a nonzero audited adjustment with a nonnegative net total.']);
            }
            $adjustment = $locked->adjustments()->make();
            $adjustment->forceFill(['admin_id' => $admin->id, 'amount_minor' => $amount, 'reason' => $reason])->save();
            OperationsAuditLog::record($admin, 'lost_compensation_adjusted', $locked, 'Parcel #'.$locked->seller_order_id,
                ['net_minor' => $net], ['net_minor' => $net + $amount], $reason);
        }, 3);
    }

    public function verifyFind(ParcelLossCase $case, LogisticsProvider $provider, int $hub, string $tracking): string
    {
        $this->authorizeFind($case, $provider, $hub);
        if ($case->sellerOrder->tracking_number === null || ! hash_equals($case->sellerOrder->tracking_number, trim($tracking))) {
            throw ValidationException::withMessages(['tracking_number' => 'Scan the tracking number on this parcel.']);
        }

        return Crypt::encryptString(json_encode([$case->id, $provider->id, $hub, now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function recordFind(ParcelLossCase $case, LogisticsProvider $provider, int $hub, string $token): void
    {
        try {
            $scan = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            $scan = null;
        }
        if (! is_array($scan) || array_slice($scan, 0, 3) !== [$case->id, $provider->id, $hub] || ($scan[3] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['verification_token' => 'Scan the parcel again at the selected hub.']);
        }

        DB::transaction(function () use ($case, $provider, $hub): void {
            $parcel = SellerOrder::query()->whereKey($case->seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = ParcelLossCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->authorizeFind($locked, $provider, $hub);
            if ($locked->recovered_at !== null) {
                if ((int) $locked->recovered_hub_id !== $hub) {
                    throw ValidationException::withMessages(['hub' => 'The parcel has already been found at a different hub.']);
                }

                return;
            }
            $locked->forceFill(['recovered_hub_id' => $hub, 'recovered_at' => now()])->save();
            $parcel->forceFill(['custody_hub_id' => $hub, 'custody_provider_id' => $provider->id])->save();
            OperationsAuditLog::record($provider, 'lost_parcel_recovered', $locked, 'Parcel #'.$parcel->id,
                null, ['hub_id' => $hub, 'status' => $parcel->status->value], 'Tracking verified at the recovery hub.');
            Notification::send(Admin::query()->whereIn('role', ['admin', 'super_admin'])->get(), new LostParcelFound($locked));
        }, 3);
    }

    private function authorizeFind(ParcelLossCase $case, LogisticsProvider $provider, int $hub): void
    {
        $parcel = $case->sellerOrder;
        $participant = ParcelHandoff::query()->where('seller_order_id', $parcel->id)->whereNotNull('dispatched_at')
            ->where(fn ($query) => $query->where('origin_provider_id', $provider->id)->orWhere('destination_provider_id', $provider->id))
            ->exists();
        abort_unless($participant || in_array((int) $provider->id, [
            (int) $parcel->carrier_provider_id,
            (int) $parcel->custody_provider_id,
            (int) $case->logistics_provider_id,
        ], true), 404);
        if (! LogisticsHub::query()->whereKey($hub)->where('logistics_provider_id', $provider->id)->operational()->exists()) {
            throw ValidationException::withMessages(['hub' => 'Select one of your operational hubs.']);
        }
    }

    public function recordDisposition(ParcelLossCase $case, Admin $admin, string $disposition, string $note): void
    {
        self::authorize($admin);
        DB::transaction(function () use ($case, $admin, $disposition, $note): void {
            $locked = ParcelLossCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->recovered_at === null || $locked->disposition !== null) {
                throw ValidationException::withMessages(['disposition' => 'Only an undisposed parcel already verified at a hub can receive a disposition.']);
            }
            $locked->forceFill(['disposition' => $disposition])->save();
            OperationsAuditLog::record($admin, 'lost_parcel_disposition', $locked, 'Parcel #'.$locked->seller_order_id,
                null, ['disposition' => $disposition], $note);
        }, 3);
    }
}

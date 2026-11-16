<?php

namespace App\Services\Logistics;

use App\Enums\AdminRole;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\AllocationPolicyApproval;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two providers keep the existing 40/60 split (CodRemittance). With three or
 * more, each provider that handled the parcel gets an auditable record with no
 * amount. Amounts and payouts need an Admin-approved policy; nothing here adds
 * shipping charges or Courier earnings.
 */
class ProviderAllocationService
{
    public const MIN_CHAIN_FOR_ALLOCATION = 3;

    /** @return list<int> distinct provider ids in handling order */
    public function chain(SellerOrder $parcel): array
    {
        return $this->handlingChain($parcel, false);
    }

    private function handlingChain(SellerOrder $parcel, bool $lock): array
    {
        $ids = array_values(array_filter([(int) $parcel->carrier_provider_id]));
        ParcelHandoff::query()
            ->where('seller_order_id', $parcel->id)
            ->where('direction', 'forward')
            ->where('status', ParcelHandoffStatus::Received->value)
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['origin_provider_id', 'destination_provider_id'])
            ->each(function ($handoff) use (&$ids): void {
                foreach ([$handoff->origin_provider_id, $handoff->destination_provider_id] as $id) {
                    if (! in_array((int) $id, $ids, true)) {
                        $ids[] = (int) $id;
                    }
                }
            });

        return $ids;
    }

    /** A policy note alone is not an implemented, validated multi-provider calculation. */
    public function assertSupported(CodRemittance $remittance): void
    {
        $parcel = SellerOrder::query()->whereKey($remittance->seller_order_id)->lockForUpdate()->firstOrFail();
        $ids = $this->handlingChain($parcel, true);
        $ids[] = (int) $remittance->logistics_provider_id;
        if (count(array_unique(array_filter($ids))) >= self::MIN_CHAIN_FOR_ALLOCATION) {
            throw ValidationException::withMessages(['allocation' => 'This parcel involves three or more Logistics Providers. Shipping allocation requires an explicitly approved and implemented method before financial finalization. Delivery and physical cash receipt remain available.']);
        }
    }

    /** Create the allocation records for a 3+ provider parcel. Safe to repeat. */
    public function prepare(SellerOrder $parcel): int
    {
        if (! RuntimeSchema::hasTable('parcel_provider_allocations')) {
            return 0;
        }

        return DB::transaction(function () use ($parcel): int {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            $chain = $this->chain($parcel);
            if (count($chain) < self::MIN_CHAIN_FOR_ALLOCATION) {
                return 0;
            }

            $created = 0;
            $last = count($chain) - 1;
            foreach ($chain as $index => $providerId) {
                $exists = ParcelProviderAllocation::query()
                    ->where('seller_order_id', $parcel->id)
                    ->where('logistics_provider_id', $providerId)
                    ->exists();
                if ($exists) {
                    continue;
                }

                (new ParcelProviderAllocation)->forceFill([
                    'seller_order_id' => $parcel->id,
                    'logistics_provider_id' => $providerId,
                    'role' => $index === 0 ? 'origin' : ($index === $last ? 'destination' : 'intermediate'),
                    'sequence' => $index + 1,
                    'status' => 'pending_policy',
                ])->save();
                $created++;
            }

            return $created;
        }, 3);
    }

    public function policyApproved(): bool
    {
        return RuntimeSchema::hasTable('allocation_policy_approvals')
            && AllocationPolicyApproval::query()->exists();
    }

    /** New 3+ provider payouts need both an approved policy and its implemented calculation. */
    public function payoutsEnabled(): bool
    {
        // A free-text approval is governance context, not an executable allocation method.
        // No 3+ provider allocation algorithm has been approved and implemented here.
        return false;
    }

    public function approvePolicy(Admin $admin, string $note): AllocationPolicyApproval
    {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
        $note = trim($note);
        if (mb_strlen($note) < 20 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['policy_note' => 'Describe the approved allocation policy in 20 to 2000 characters.']);
        }

        return DB::transaction(function () use ($admin, $note): AllocationPolicyApproval {
            $approval = new AllocationPolicyApproval;
            $approval->forceFill(['admin_id' => $admin->id, 'policy_note' => $note, 'approved_at' => now()])->save();
            OperationsAuditLog::record($admin, 'allocation_policy_approved', $approval, 'Allocation policy #'.$approval->id, null, ['policy_note_recorded' => true, 'payouts_enabled' => false], $note);

            return $approval;
        });
    }
}

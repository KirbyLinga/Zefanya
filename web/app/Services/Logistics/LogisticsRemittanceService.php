<?php

namespace App\Services\Logistics;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Notifications\Admin\LogisticsPaymentRecorded;
use App\Notifications\Logistics\LogisticsPaymentDecided;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Logistics to Zefanya money: a company keeps each parcel's shipping fee and
 * sends Zefanya the rest of the cash. Zefanya confirms what actually arrived.
 */
class LogisticsRemittanceService
{
    public const STAGE_RIDER = 'rider';

    public const STAGE_READY = 'ready';

    public const STAGE_SENT = 'sent';

    public const STAGE_SETTLED = 'settled';

    /**
     * Parcels, cash and shipping in each stage of the journey to Zefanya.
     *
     * @return array<string, array{parcels: int, codMinor: int, shippingMinor: int, dueMinor: int}>
     */
    public function totals(LogisticsProvider $provider): array
    {
        $empty = ['parcels' => 0, 'codMinor' => 0, 'shippingMinor' => 0, 'dueMinor' => 0];
        $totals = array_fill_keys([self::STAGE_RIDER, self::STAGE_READY, self::STAGE_SENT, self::STAGE_SETTLED], $empty);

        $rows = $this->withShipping(CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey()))
            ->selectRaw(
                'CASE WHEN cod_remittances.status = ? THEN ? WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN ? '
                .'WHEN cod_remittances.logistics_remittance_id IS NOT NULL THEN ? ELSE ? END as stage',
                [CodRemittance::PENDING_REMITTANCE, self::STAGE_RIDER, self::STAGE_SETTLED, self::STAGE_SENT, self::STAGE_READY],
            )
            ->selectRaw('COUNT(*) as parcels, SUM(cod_remittances.amount_minor) as cod_minor, SUM(seller_orders.shipping_fee_minor) as shipping_minor')
            ->groupBy('stage')
            ->get();

        foreach ($rows as $row) {
            $totals[$row->stage] = [
                'parcels' => (int) $row->parcels,
                'codMinor' => (int) $row->cod_minor,
                'shippingMinor' => (int) $row->shipping_minor,
                'dueMinor' => (int) $row->cod_minor - (int) $row->shipping_minor,
            ];
        }

        return $totals;
    }

    /**
     * What each company has collected but not yet reported sending.
     *
     * @return Collection<int, array{provider: string, parcels: int, dueMinor: int}>
     */
    public function unsentByProvider(): Collection
    {
        return $this->withShipping($this->readyQuery(CodRemittance::query()))
            ->selectRaw('cod_remittances.logistics_provider_id, COUNT(*) as parcels, SUM(cod_remittances.amount_minor) as cod_minor, SUM(seller_orders.shipping_fee_minor) as shipping_minor')
            ->groupBy('cod_remittances.logistics_provider_id')
            ->with('provider:id,business_name')
            ->get()
            ->map(fn (CodRemittance $row): array => [
                'provider' => $row->provider?->business_name ?? 'Unknown provider',
                'parcels' => (int) $row->parcels,
                'dueMinor' => (int) $row->cod_minor - (int) $row->shipping_minor,
            ])
            ->sortByDesc('dueMinor')
            ->values();
    }

    /**
     * Parcels the company can include in its next payment, oldest first.
     *
     * @return Collection<int, CodRemittance>
     */
    public function readyParcels(LogisticsProvider $provider): Collection
    {
        return $this->readyQuery(CodRemittance::query()->where('logistics_provider_id', $provider->getKey()))
            ->with('sellerOrder:id,tracking_number,shipping_fee_minor')
            ->orderBy('id')->get();
    }

    /**
     * Record that the company sent Zefanya the money for the chosen parcels. The
     * expected amount is what the page showed, so a stale page stops the save
     * instead of recording a different amount from what was actually sent.
     *
     * @param  list<int>  $parcelIds
     */
    public function submit(LogisticsProvider $provider, array $parcelIds, int $expectedMinor, string $reference): LogisticsRemittance
    {
        $reference = trim($reference);

        $remittance = DB::transaction(function () use ($provider, $parcelIds, $expectedMinor, $reference): LogisticsRemittance {
            $provider = LogisticsProvider::query()->whereKey($provider->getKey())->lockForUpdate()->firstOrFail();

            $used = LogisticsRemittance::query()
                ->where('logistics_provider_id', $provider->getKey())
                ->where('reference', $reference)
                ->where('status', '!=', LogisticsRemittance::REJECTED)
                ->exists();
            if ($used) {
                throw ValidationException::withMessages(['reference' => 'This reference was already used for another payment.']);
            }

            $parcels = $this->readyQuery(CodRemittance::query()->where('logistics_provider_id', $provider->getKey()))
                ->whereKey(array_unique($parcelIds))
                ->with('sellerOrder:id,shipping_fee_minor')
                ->orderBy('id')->lockForUpdate()->get();
            if ($parcelIds === [] || $parcels->count() !== count(array_unique($parcelIds))) {
                throw ValidationException::withMessages(['remittance' => 'One of these parcels can no longer be sent. Check the list and try again.']);
            }

            $cod = (int) $parcels->sum('amount_minor');
            $shipping = (int) $parcels->sum(fn (CodRemittance $parcel): int => (int) $parcel->sellerOrder?->shipping_fee_minor);
            $amount = $cod - $shipping;
            if ($amount !== $expectedMinor) {
                throw ValidationException::withMessages(['remittance' => 'The amount due changed. Check the new amount before recording your payment.']);
            }

            $remittance = new LogisticsRemittance;
            $remittance->forceFill([
                'logistics_provider_id' => $provider->getKey(),
                'parcel_count' => $parcels->count(),
                'cod_total_minor' => $cod,
                'shipping_total_minor' => $shipping,
                'amount_minor' => $amount,
                'reference' => $reference,
                'status' => LogisticsRemittance::SUBMITTED,
            ])->save();

            CodRemittance::query()->whereKey($parcels->modelKeys())
                ->update(['logistics_remittance_id' => $remittance->getKey()]);

            OperationsAuditLog::record(
                $provider, 'logistics_remittance_submitted', $remittance, 'Payment #'.$remittance->getKey(),
                ['status' => null, 'amount' => null],
                ['status' => 'Submitted', 'amount' => $this->peso($amount), 'reference' => $reference],
            );

            return $remittance;
        }, 3);

        $this->safely(fn () => Notification::send(
            Admin::query()->whereIn('role', [AdminRole::Admin->value, AdminRole::SuperAdmin->value])->get(),
            new LogisticsPaymentRecorded($remittance->load('provider')),
        ));

        return $remittance;
    }

    /** Return false for an already-confirmed payment without changing its record. */
    public function confirm(LogisticsRemittance $remittance, Admin $admin, int $receivedMinor): bool
    {
        $this->authorizeAdmin($admin);

        $confirmed = DB::transaction(function () use ($remittance, $admin, $receivedMinor): bool {
            $locked = LogisticsRemittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === LogisticsRemittance::CONFIRMED) {
                return false;
            }
            if ($locked->status !== LogisticsRemittance::SUBMITTED) {
                throw ValidationException::withMessages(['remittance' => 'Only a submitted payment can be confirmed.']);
            }
            if ($receivedMinor !== $locked->amount_minor) {
                throw ValidationException::withMessages([
                    'received_amount' => 'The amount received must match exactly. If it differs, reject this payment with the reason.',
                ]);
            }

            $locked->forceFill([
                'status' => LogisticsRemittance::CONFIRMED,
                'received_amount_minor' => $receivedMinor,
                'decided_by_admin_id' => $admin->getKey(),
                'confirmed_at' => now(),
            ])->save();

            CodRemittance::query()->where('logistics_remittance_id', $locked->getKey())
                ->update(['zefanya_received_at' => $locked->confirmed_at]);

            OperationsAuditLog::record(
                $admin, 'logistics_remittance_confirmed', $locked, 'Payment #'.$locked->getKey(),
                ['status' => 'Submitted', 'received' => null],
                ['status' => 'Confirmed', 'received' => $this->peso($receivedMinor)],
            );

            return true;
        }, 3);

        if ($confirmed) {
            $this->tellCompany($remittance);
        }

        return $confirmed;
    }

    /** The money did not arrive as stated, so its parcels become due again. */
    public function reject(LogisticsRemittance $remittance, Admin $admin, string $reason): void
    {
        $this->authorizeAdmin($admin);

        DB::transaction(function () use ($remittance, $admin, $reason): void {
            $locked = LogisticsRemittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== LogisticsRemittance::SUBMITTED) {
                throw ValidationException::withMessages(['remittance' => 'Only a submitted payment can be rejected.']);
            }

            $locked->forceFill([
                'status' => LogisticsRemittance::REJECTED,
                'decided_by_admin_id' => $admin->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            CodRemittance::query()->where('logistics_remittance_id', $locked->getKey())
                ->update(['logistics_remittance_id' => null]);

            OperationsAuditLog::record(
                $admin, 'logistics_remittance_rejected', $locked, 'Payment #'.$locked->getKey(),
                ['status' => 'Submitted'], ['status' => 'Rejected'], $reason,
            );
        }, 3);

        $this->tellCompany($remittance);
    }

    private function tellCompany(LogisticsRemittance $remittance): void
    {
        $this->safely(function () use ($remittance): void {
            $fresh = $remittance->fresh('provider');
            $fresh->provider?->notify(new LogisticsPaymentDecided($fresh));
        });
    }

    /** The money record is already saved, so a mail failure must not undo the page. */
    private function safely(callable $notify): void
    {
        try {
            $notify();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Cash the company confirmed from its riders, not yet in any payment. */
    private function readyQuery(Builder $query): Builder
    {
        return $query->where('cod_remittances.status', CodRemittance::REMITTED_TO_LOGISTICS)
            ->whereNull('cod_remittances.logistics_remittance_id')
            ->whereNull('cod_remittances.zefanya_received_at');
    }

    private function withShipping(Builder $query): Builder
    {
        return $query->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id');
    }

    private function authorizeAdmin(Admin $admin): void
    {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
    }

    private function peso(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }
}

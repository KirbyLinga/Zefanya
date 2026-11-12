<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\DeliveryDispute;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryDisputeService
{
    public static function installed(): bool
    {
        return RuntimeSchema::hasTable('delivery_disputes');
    }

    public function deadline(SellerOrder $shipment): ?CarbonInterface
    {
        if (! self::installed() || ! in_array($shipment->status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
            return null;
        }

        $delivery = $shipment->statusHistories()
            ->where('to_status', OrderStatus::Delivered->value)->latest('id')->first();
        $at = $delivery?->created_at;

        return $at !== null && ! $at->isFuture() ? $at->copy()->addHours(168) : null;
    }

    public function buyerView(SellerOrder $shipment): array
    {
        $case = self::installed() ? $shipment->deliveryDispute : null;
        $deadline = $case === null ? $this->deadline($shipment) : $case->delivered_at->copy()->addHours(168);

        return [
            'can_report' => $case === null && $deadline !== null && now()->lt($deadline),
            'deadline' => $deadline?->toIso8601String(),
            'case' => $case === null ? null : ['id' => $case->id, 'status' => $case->status, 'outcome' => $case->outcome],
        ];
    }

    public function open(SellerOrder $shipment, Buyer $buyer, string $explanation, ?string $evidencePath = null, ?Admin $exceptionAdmin = null, ?string $exceptionReason = null): DeliveryDispute
    {
        $this->require(self::installed(), 'Delivery dispute setup is pending.');
        if ($exceptionAdmin !== null) {
            abort_unless(in_array($exceptionAdmin->role, ['admin', 'super_admin'], true), 403);
            $this->require(filled(trim((string) $exceptionReason)), 'Record why this late report was accepted.');
        }

        return DB::transaction(function () use ($shipment, $buyer, $explanation, $evidencePath, $exceptionAdmin, $exceptionReason): DeliveryDispute {
            $order = SellerOrder::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->order->buyer_id === (int) $buyer->id, 404);
            $existing = $order->deliveryDispute()->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $deadline = $this->deadline($order);
            $this->require($deadline !== null && ($exceptionAdmin !== null || now()->lt($deadline)), 'The seven-day reporting window is closed or the delivery time could not be verified. Contact support for review.');
            $this->require(filled(trim($explanation)) && mb_strlen(trim($explanation)) <= 1000, 'Enter a short explanation of what happened.');
            $provider = $order->deliveryProof?->courier?->logistics_provider_id ?? $order->deliveryCourier?->logistics_provider_id;
            $settlement = $order->settlement()->lockForUpdate()->first();

            $case = new DeliveryDispute;
            $case->forceFill([
                'seller_order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'logistics_provider_id' => $provider,
                'status' => $provider === null ? DeliveryDispute::ESCALATED : DeliveryDispute::OPEN,
                'explanation' => trim($explanation),
                'evidence_path' => $evidencePath,
                'delivered_at' => $deadline->copy()->subHours(168),
                'already_paid_minor' => $settlement?->paid_amount_minor ?? 0,
            ])->save();
            if ($exceptionAdmin !== null) {
                OperationsAuditLog::record($exceptionAdmin, 'delivery_dispute_exception_opened', $case, 'Delivery dispute #'.$case->id,
                    null, ['shipment_id' => $order->id], $exceptionReason);
            }

            return $case;
        }, 3);
    }

    public function review(DeliveryDispute $case, LogisticsProvider|Admin $actor, string $action, string $note, ?string $investigator = null): void
    {
        DB::transaction(function () use ($case, $actor, $action, $note, $investigator): void {
            SellerOrder::query()->whereKey($case->seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = DeliveryDispute::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $admin = $actor instanceof Admin;
            abort_unless($admin
                ? in_array($actor->role, ['admin', 'super_admin'], true)
                : (int) $locked->logistics_provider_id === (int) $actor->id, 403);
            $this->require(filled(trim($note)) && mb_strlen(trim($note)) <= 1000, 'Record your investigation note.');
            if ($admin && $action === 'release_hold') {
                $this->require($locked->status === DeliveryDispute::RESOLVED && $locked->outcome === 'upheld'
                    && $locked->financial_reviewed_at === null, 'Only an upheld dispute with an outstanding financial hold can be cleared.');
                $locked->forceFill(['financial_reviewed_at' => now()])->save();
                OperationsAuditLog::record($actor, 'delivery_dispute_finance_reviewed', $locked, 'Delivery dispute #'.$locked->id,
                    ['financial_hold' => true], ['financial_hold' => false], $note);

                return;
            }
            $this->require($locked->status !== DeliveryDispute::RESOLVED, 'This dispute is already resolved.');

            if ($admin) {
                $this->require($locked->status === DeliveryDispute::ESCALATED && in_array($action, ['upheld', 'denied'], true), 'Admin can only decide escalated disputes.');
            } else {
                $this->require(in_array($locked->status, [DeliveryDispute::OPEN, DeliveryDispute::INVESTIGATING], true)
                    && in_array($action, ['investigate', 'escalate', 'upheld', 'denied'], true), 'This dispute is no longer available for Logistics review.');
                $this->require(filled(trim((string) $investigator)) && mb_strlen(trim((string) $investigator)) <= 120, 'Name the responsible Logistics investigator.');
            }

            $old = $locked->only(['status', 'investigator', 'decision', 'outcome']);
            $locked->forceFill([
                'status' => match ($action) {
                    'investigate' => DeliveryDispute::INVESTIGATING,
                    'escalate' => DeliveryDispute::ESCALATED,
                    default => DeliveryDispute::RESOLVED,
                },
                'investigator' => $admin ? $locked->investigator : trim($investigator),
                'decision' => trim($note),
                'outcome' => in_array($action, ['upheld', 'denied'], true) ? $action : null,
            ])->save();
            OperationsAuditLog::record($actor, 'delivery_dispute_'.$action, $locked, 'Delivery dispute #'.$locked->id, $old, $locked->only(array_keys($old)), $note);
        }, 3);
    }

    public static function holdsSettlement(SellerOrder $shipment, bool $lock = false): bool
    {
        if (! self::installed()) {
            return false;
        }

        $query = $shipment->deliveryDispute();
        $case = ($lock ? $query->lockForUpdate() : $query)->first();

        // An upheld claim remains a finance follow-up. A denial releases the hold.
        return $case !== null && ($case->status !== DeliveryDispute::RESOLVED
            || ($case->outcome === 'upheld' && $case->financial_reviewed_at === null));
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['delivery_dispute' => $message]);
        }
    }
}

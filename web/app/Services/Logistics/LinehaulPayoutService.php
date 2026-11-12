<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulTripPayout;
use App\Models\Logistics\LinehaulTripPayoutCorrection;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestAuditLog;
use App\Policies\LinehaulPolicy;
use App\Support\RuntimeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LinehaulPayoutService
{
    public static function installed(): bool
    {
        return LinehaulPayService::installed() && RuntimeSchema::hasTable('linehaul_trip_payouts');
    }

    public static function rules(): array
    {
        return [
            'expected_amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:100'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'replaces_payout_id' => ['nullable', 'integer', 'min:1'],
            'amount_minor' => ['prohibited'],
            'driver_id' => ['prohibited'],
            'logistics_provider_id' => ['prohibited'],
        ];
    }

    public static function correctionsInstalled(): bool
    {
        return self::installed() && RuntimeSchema::hasTable('linehaul_trip_payout_corrections')
            && RuntimeSchema::hasColumn('linehaul_trip_payouts', 'sequence');
    }

    public static function correctionRules(): array
    {
        return [
            'payout_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:reference,failed'],
            'expected_reference' => ['required', 'string', 'max:100'],
            'reference' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'staff_name' => ['required', 'string', 'max:120'],
            'external_failure_confirmed' => ['exclude_unless:action,failed', 'required', 'accepted'],
        ];
    }

    public function record(LogisticsProvider $provider, Manifest $manifest, array $input, Request $request): LinehaulTripPayout
    {
        abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
        $this->require(self::installed(), 'Courier payout setup is pending.');
        $data = Validator::make($input, self::rules())->validate();
        $reference = trim($data['reference']);
        $this->require($reference !== '', 'Enter the payment reference.');

        return DB::transaction(function () use ($provider, $manifest, $data, $reference, $request): LinehaulTripPayout {
            $manifest = Manifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $provider = $provider->fresh();
            abort_unless($provider !== null && (new LinehaulPolicy)->assign($provider, $manifest), 403);
            $assignment = LinehaulAssignment::currentOffer()->where('manifest_id', $manifest->id)->lockForUpdate()->first();
            $this->require($assignment !== null, 'This trip has no Courier assignment.');
            $assignment->setRelation('manifest', $manifest);
            $pay = LinehaulPayService::details($assignment);
            $this->require($pay['status'] === 'earned', 'Only confirmed earned trips can be recorded as paid.');
            $this->require($pay['amount_minor'] === (int) $data['expected_amount_minor'], 'The trip amount changed. Refresh before recording payment.');
            $this->require($data['paid_on'] >= $manifest->received_at->copy()->timezone('Asia/Manila')->toDateString(), 'The payment date must be on or after handover.');

            $existing = $assignment->payout()->lockForUpdate()->first();
            if ($existing !== null) {
                $this->require($existing->recordedReference() === $reference && $existing->paid_on->toDateString() === $data['paid_on']
                    && $existing->amount_minor === $pay['amount_minor'], 'A payment is already recorded for this trip.');

                return $existing;
            }

            $previous = self::correctionsInstalled() ? $assignment->payouts()->latest('id')->lockForUpdate()->first() : null;
            if ($previous !== null) {
                $this->require($previous->reversal()->exists()
                    && $previous->id === (int) ($data['replaces_payout_id'] ?? 0), 'Confirm which failed payment this replaces before recording the new payment.');
                $this->require($reference !== $previous->recordedReference() && $reference !== $previous->reference,
                    'Enter a new payment reference for the replacement transfer.');
            } else {
                $this->require(empty($data['replaces_payout_id']), 'This trip has no reversed payment to replace.');
            }
            $payout = new LinehaulTripPayout;
            $payout->forceFill([
                'linehaul_assignment_id' => $assignment->id,
                'linehaul_driver_id' => $assignment->linehaul_driver_id,
                'logistics_provider_id' => $provider->id,
                'amount_minor' => $pay['amount_minor'],
                'reference' => $reference,
                'paid_on' => $data['paid_on'],
                ...(self::correctionsInstalled() ? ['sequence' => ((int) $previous?->sequence) + 1] : []),
            ])->save();
            ManifestAuditLog::record($provider, 'courier_payout_recorded', $manifest, null, $request);

            return $payout;
        }, 3);
    }

    public function correct(LogisticsProvider $provider, Manifest $manifest, array $input, Request $request): void
    {
        abort_unless((new LinehaulPolicy)->assign($provider, $manifest), 403);
        $this->require(self::correctionsInstalled(), 'Courier payout correction setup is pending.');
        $data = Validator::make($input, self::correctionRules())->validate();

        DB::transaction(function () use ($provider, $manifest, $data, $request): void {
            $manifest = Manifest::query()->whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            $provider = $provider->fresh();
            abort_unless($provider !== null && (new LinehaulPolicy)->assign($provider, $manifest), 403);
            $assignment = LinehaulAssignment::currentOffer()->where('manifest_id', $manifest->id)->lockForUpdate()->firstOrFail();
            $payment = $assignment->payout()->lockForUpdate()->first();
            $this->require($payment !== null && $payment->id === (int) $data['payout_id']
                && $payment->recordedReference() === trim($data['expected_reference']), 'The recorded payment changed. Refresh before correcting it.');
            $reference = trim($data['reference']);
            $failed = $data['action'] === 'failed';
            $this->require($reference !== '' && trim($data['staff_name']) !== '' && mb_strlen(trim($data['reason'])) >= 10,
                'Enter the verified reference, authorizing staff name and a reason of at least 10 characters.');
            $this->require($failed || $reference !== $payment->recordedReference(), 'The corrected reference must differ from the current reference.');
            $correction = new LinehaulTripPayoutCorrection;
            $correction->forceFill([
                'linehaul_trip_payout_id' => $payment->id,
                'reversed_payout_id' => $failed ? $payment->id : null,
                'logistics_provider_id' => $provider->id,
                'staff_name' => trim($data['staff_name']),
                'previous_reference' => $payment->recordedReference(),
                'reference' => $reference,
                'reason' => trim($data['reason']),
            ])->save();
            ManifestAuditLog::record($provider, $failed ? 'courier_payout_reversed' : 'courier_payout_reference_corrected', $manifest, null, $request);
            LinehaulPushService::queue((int) $assignment->linehaul_driver_id, $provider->id, earningsChanged: true);
        }, 3);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['payout' => $message]);
        }
    }
}

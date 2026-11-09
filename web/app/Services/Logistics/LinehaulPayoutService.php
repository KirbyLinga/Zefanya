<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulTripPayout;
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
            'amount_minor' => ['prohibited'],
            'driver_id' => ['prohibited'],
            'logistics_provider_id' => ['prohibited'],
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
                $this->require($existing->reference === $reference && $existing->paid_on->toDateString() === $data['paid_on']
                    && $existing->amount_minor === $pay['amount_minor'], 'A payment is already recorded for this trip.');

                return $existing;
            }

            $payout = new LinehaulTripPayout;
            $payout->forceFill([
                'linehaul_assignment_id' => $assignment->id,
                'linehaul_driver_id' => $assignment->linehaul_driver_id,
                'logistics_provider_id' => $provider->id,
                'amount_minor' => $pay['amount_minor'],
                'reference' => $reference,
                'paid_on' => $data['paid_on'],
            ])->save();
            ManifestAuditLog::record($provider, 'courier_payout_recorded', $manifest, null, $request);

            return $payout;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['payout' => $message]);
        }
    }
}

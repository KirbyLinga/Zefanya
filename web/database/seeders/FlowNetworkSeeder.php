<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Seeder;

/**
 * "Ensure" pass over the pickup/delivery network of one real Logistics
 * company, using the real Seller/Buyer addresses already on file.
 *
 * Creates only what is missing (Province Hub, Station Hub, barangay coverage,
 * seller opt-in, fleet Courier). Never changes approval decisions, passwords,
 * emails or roles.
 *
 * Stale reservations: a pickup rider reserved at READY_FOR_PICKUP whose
 * dispatch was never confirmed still counts against Courier::ACTIVE_CAPACITY
 * (one active leg per rider). Those reservations are released — the
 * delivery_assignments history row is kept — so the fleet can take work again.
 */
class FlowNetworkSeeder extends Seeder
{
    /** @var array{created: list<string>, updated: list<string>, warnings: list<string>} */
    private array $report = ['created' => [], 'updated' => [], 'warnings' => []];

    /**
     * @return array{created: list<string>, updated: list<string>, warnings: list<string>}
     */
    public function seed(Buyer $buyer, Seller $seller, LogisticsProvider $provider, Rider $rider): array
    {
        $this->report = ['created' => [], 'updated' => [], 'warnings' => []];

        if (! $provider->isApproved() || ! $provider->hasVerifiedProvince()) {
            $this->report['warnings'][] = 'Logistics #'.$provider->id.' is not approved with a verified province — network setup skipped.';

            return $this->report;
        }

        $province = $this->provinceHub($provider);
        $station = $this->stationHub($provider, $province, $seller);

        if ($station === null || ! $station->isOperational() || $station->parent?->isOperational() !== true) {
            $this->report['warnings'][] = 'Station Hub is not operational (approved + active under a live Province Hub) — coverage/rider steps skipped.';

            return $this->report;
        }

        $this->coverage($provider, $station, $seller->barangay_code, $seller->barangay_name, $seller->municipality_name);
        $this->coverage($provider, $station, $buyer->barangay_code, $buyer->barangay_name, $buyer->municipality_name);

        $this->sellerOptIn($seller, $provider);
        $this->releaseUnconfirmedPickups($provider);
        $this->courier($provider, $station, $rider, $seller);

        return $this->report;
    }

    private function provinceHub(LogisticsProvider $provider): LogisticsHub
    {
        $hub = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->liveProvince()
            ->first();

        if ($hub !== null) {
            if (! $hub->isOperational()) {
                $this->report['warnings'][] = 'Province Hub #'.$hub->id.' is not operational.';
            }

            return $hub;
        }

        $hub = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => trim($provider->province_name.' Province Hub'),
            'hub_type' => LogisticsHub::PROVINCE,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);

        $this->report['created'][] = 'Logistics: Province Hub #'.$hub->id.' ('.$hub->name.')';

        return $hub;
    }

    private function stationHub(LogisticsProvider $provider, LogisticsHub $province, Seller $seller): ?LogisticsHub
    {
        $city = $seller->municipality_name;

        if (blank($city)) {
            $this->report['warnings'][] = 'Seller has no municipality_name, so no Station Hub can be resolved.';

            return null;
        }

        $station = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('municipality', $city)
            ->first();

        if ($station !== null) {
            return $station;
        }

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $city.' Station Hub',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'region_name' => $province->region_name,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => $seller->municipality_code,
            'municipality' => $city,
            'barangay_code' => $seller->barangay_code,
            'barangay_name' => $seller->barangay_name,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ]);

        $this->report['created'][] = 'Logistics: Station Hub #'.$station->id.' ('.$station->name.')';

        return $station;
    }

    private function coverage(LogisticsProvider $provider, LogisticsHub $station, ?string $code, ?string $barangay, ?string $city): void
    {
        if (blank($code)) {
            $this->report['warnings'][] = 'A party has no barangay_code — skipped coverage for it.';

            return;
        }

        $area = Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('barangay_code', $code)
            ->first();

        if ($area === null) {
            $area = Area::query()->create([
                'logistics_provider_id' => $provider->id,
                'station_hub_id' => $station->id,
                'name' => $barangay ?? $code,
                'municipality' => $city ?? $station->municipality,
                'city_municipality_code' => $station->city_municipality_code,
                'barangay_code' => $code,
                'barangay_name' => $barangay,
                'coverage_validated_at' => now(),
                'is_active' => true,
            ]);

            $this->report['created'][] = 'Logistics: barangay coverage #'.$area->id.' ('.$code.' '.($barangay ?? '').')';

            return;
        }

        if ((int) $area->station_hub_id !== (int) $station->id) {
            // Moving existing coverage can redirect parcels already in flight.
            $this->report['warnings'][] = 'Coverage #'.$area->id.' for barangay '.$code.' belongs to another Station Hub — left unchanged.';

            return;
        }

        $changed = false;

        if (! $area->is_active) {
            $area->is_active = true;
            $changed = true;
        }

        if ($area->coverage_validated_at === null) {
            $area->coverage_validated_at = now();
            $changed = true;
        }

        if ($changed) {
            $area->save();
            $this->report['updated'][] = 'Logistics: activated/validated coverage #'.$area->id.' for barangay '.$code;
        }
    }

    private function sellerOptIn(Seller $seller, LogisticsProvider $provider): void
    {
        $optIn = SellerShippingProvider::query()->firstOrCreate(
            ['seller_id' => $seller->id, 'logistics_provider_id' => $provider->id],
            ['is_enabled' => true],
        );

        if ($optIn->wasRecentlyCreated) {
            $this->report['created'][] = 'Logistics: seller shipping opt-in #'.$optIn->id.' (seller '.$seller->id.' -> provider '.$provider->id.')';

            return;
        }

        if (! $optIn->is_enabled) {
            $optIn->forceFill(['is_enabled' => true])->save();
            $this->report['updated'][] = 'Logistics: re-enabled seller shipping opt-in #'.$optIn->id;
        }
    }

    private function releaseUnconfirmedPickups(LogisticsProvider $provider): void
    {
        $parcels = SellerOrder::query()
            ->where('status', OrderStatus::ReadyForPickup->value)
            ->whereNotNull('courier_id')
            ->whereNull('handover_at')
            ->whereHas('courier', fn ($query) => $query->where('logistics_provider_id', $provider->id))
            ->with(['courier', 'deliveryAssignments'])
            ->lockForUpdate()
            ->get();

        foreach ($parcels as $parcel) {
            $latest = $parcel->deliveryAssignments
                ->where('role', 'pickup')
                ->sortByDesc('id')
                ->first();

            $notes = json_decode((string) $latest?->notes, true);
            $notes = is_array($notes) ? $notes : [];

            if (filled($notes['dispatch_confirmed_at'] ?? null)) {
                // The rider was already told to collect it — that is real work.
                continue;
            }

            $courier = $parcel->courier;

            $parcel->forceFill([
                'courier_id' => null,
                'needs_reassignment' => false,
                'reassignment_reason' => null,
                'reassignment_flagged_at' => null,
            ])->save();

            $this->report['updated'][] = 'Logistics: released unconfirmed pickup reservation on parcel #'.$parcel->id
                .($parcel->tracking_number ? ' ('.$parcel->tracking_number.')' : '');

            if ($courier !== null) {
                $courier->forceFill([
                    'is_available' => $courier->activeWorkload() === 0 && $courier->isEligibleForAssignment(),
                ])->save();
            }
        }
    }

    private function courier(LogisticsProvider $provider, LogisticsHub $station, Rider $rider, Seller $seller): void
    {
        if ((int) $rider->logistics_provider_id !== (int) $provider->id) {
            $this->report['warnings'][] = 'Rider #'.$rider->id.' belongs to another Logistics company — fleet setup skipped.';

            return;
        }

        if ($rider->station_hub_id === null) {
            $rider->forceFill(['station_hub_id' => $station->id])->save();
            $this->report['updated'][] = 'Rider: assigned rider #'.$rider->id.' to Station Hub #'.$station->id;
        } elseif ((int) $rider->station_hub_id !== (int) $station->id) {
            $this->report['warnings'][] = 'Rider #'.$rider->id.' is stationed at hub #'.$rider->station_hub_id.' (not #'.$station->id.') — left unchanged.';
        }

        if (! $rider->isApprovedOrActive()) {
            $this->report['warnings'][] = 'Rider #'.$rider->id.' status is "'.$rider->status.'" — approve them in Logistics > Riders first.';
        }

        if ($rider->email_verified_at === null) {
            $this->report['warnings'][] = 'Rider #'.$rider->id.' email is not verified — they cannot sign in yet.';
        }

        $heaviest = (int) $seller->products()->where('status', 'active')->max('weight_grams');
        $courier = Courier::query()->where('rider_id', $rider->id)->first();

        if ($courier === null) {
            $courier = Courier::query()->create([
                'logistics_provider_id' => $provider->id,
                'rider_id' => $rider->id,
                'area_id' => Area::query()
                    ->where('logistics_provider_id', $provider->id)
                    ->where('station_hub_id', $station->id)
                    ->where('barangay_code', $seller->barangay_code)
                    ->value('id'),
                'name' => $rider->fullName(),
                'phone' => $rider->contact_no,
                'service_area' => array_values(array_filter([$seller->municipality_name])),
                'vehicle_type' => strtolower((string) $rider->vehicle_type) === 'van' ? 'van' : 'motorcycle',
                'max_weight_grams' => max(10000, $heaviest),
            ]);

            $this->report['created'][] = 'Rider: fleet courier #'.$courier->id.' for rider #'.$rider->id;
        } else {
            if ((int) $courier->logistics_provider_id !== (int) $provider->id) {
                $this->report['warnings'][] = 'Courier #'.$courier->id.' belongs to another Logistics company — left unchanged.';
            }

            if ($heaviest > 0 && $courier->max_weight_grams < $heaviest) {
                $courier->forceFill(['max_weight_grams' => $heaviest])->save();
                $this->report['updated'][] = 'Rider: raised courier #'.$courier->id.' weight limit to '.$heaviest.'g';
            }
        }

        // Only an idle, eligible rider is opened for new work.
        $available = $courier->activeWorkload() === 0 && $courier->isEligibleForAssignment();

        if ((bool) $courier->is_available !== $available) {
            $courier->forceFill(['is_available' => $available])->save();
            $this->report['updated'][] = 'Rider: '.($available ? 'opened' : 'closed').' courier #'.$courier->id.' for new work (workload '.$courier->activeWorkload().').';
        }
    }
}

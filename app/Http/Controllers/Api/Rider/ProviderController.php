<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Services\Logistics\LogisticsHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/rider/providers — the logistics companies a new rider may apply to.
 *
 * Without a barangay: the same list, with the same two columns, that the
 * public web registration page (`register.type`) renders, and the same filter
 * as the `logistics_provider_id` rule in StoreRiderRegistrationRequest
 * (approved, not archived).
 *
 * With `?barangay={PSGC code}`: only those companies that serve that address,
 * using the platform's own coverage rule
 * (LogisticsHubService::areaForBarangay — validated, active barangay coverage
 * on an operational Station Hub under an operational Province Hub). This only
 * narrows what the app offers; registration still accepts any approved
 * company, exactly as on the web.
 */
class ProviderController extends Controller
{
    use ApiResponses;

    public function index(Request $request, LogisticsHubService $hubs): JsonResponse
    {
        $data = $request->validate([
            'barangay' => ['nullable', 'string', 'regex:/\A[0-9]{9,10}\z/'],
        ]);
        $barangay = $data['barangay'] ?? null;

        $query = LogisticsProvider::query()
            ->where('status', 'approved')
            ->whereNull('archived_at')
            ->orderBy('business_name');

        if ($barangay !== null) {
            $candidates = $hubs->coverageSchemaAvailable()
                ? Area::query()->where('barangay_code', $barangay)
                    ->where('is_active', true)->whereNotNull('coverage_validated_at')
                    ->distinct()->pluck('logistics_provider_id')
                : collect();
            $query->whereIn('id', $candidates);
        }

        $providers = $query->get(['id', 'business_name'])
            ->when($barangay !== null, fn ($rows) => $rows->filter(
                fn (LogisticsProvider $provider): bool => $hubs->areaForBarangay((int) $provider->id, $barangay) !== null,
            ))
            ->map(fn (LogisticsProvider $provider): array => [
                'id' => $provider->id,
                'name' => $provider->business_name,
            ])
            ->values();

        return $this->success('Logistics providers retrieved.', [
            'barangay' => $barangay,
            'providers' => $providers,
        ]);
    }
}

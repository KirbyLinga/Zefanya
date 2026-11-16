<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsHubLocationChange;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsHubDecisionNotification;
use App\Services\Logistics\ProviderProvinceService;
use App\Services\Shared\PhilippineLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HubApprovalController extends Controller
{
    public function index(Request $request, ProviderProvinceService $provinces): View
    {
        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $status = (string) $request->query('status', 'pending');
        $type = (string) $request->query('type', 'all');
        $perPage = (int) $request->query('per_page', 25);

        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 25;
        }

        $validStatuses = ['pending', 'approved', 'rejected', 'all'];
        if (! in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }

        $validTypes = ['province_hub', 'station_hub', 'all'];
        if (! in_array($type, $validTypes, true)) {
            $type = 'all';
        }

        $pendingCount = LogisticsHub::query()->where('approval_status', LogisticsHub::APPROVAL_PENDING)->count();
        $approvedCount = LogisticsHub::query()->where('approval_status', LogisticsHub::APPROVAL_APPROVED)->count();
        $rejectedCount = LogisticsHub::query()->where('approval_status', LogisticsHub::APPROVAL_REJECTED)->count();
        $totalCount = LogisticsHub::query()->count();

        $query = LogisticsHub::query()
            ->with(['provider', 'parent.provider', 'approver', 'rejector'])
            ->when($status !== 'all', fn ($q) => $q->where('approval_status', $status))
            ->when($type !== 'all', fn ($q) => $q->where('hub_type', $type))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($sq) use ($search): void {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('province_name', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('barangay_name', 'like', "%{$search}%")
                        ->orWhereHas('provider', fn ($pq) => $pq->where('business_name', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at');

        $hubs = $query->paginate($perPage)->withQueryString();

        $locationChanges = LogisticsHubLocationChange::query()
            ->where('status', LogisticsHubLocationChange::STATUS_PENDING)
            ->with(['hub.provider', 'hub.parent'])
            ->latest('requested_at')
            ->get();

        $providersNeedingProvinceHub = LogisticsProvider::query()
            ->where('status', 'approved')
            ->whereNotNull('province_verified_at')
            ->whereNotNull('province_code')
            ->whereDoesntHave('hubs', function ($q): void {
                $q->where('hub_type', LogisticsHub::PROVINCE)
                    ->where('active_province_slot', 1)
                    ->whereNull('archived_at');
            })
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'province_code', 'province_name', 'email']);

        $selectedProviderId = (int) $request->query('provision_provider', 0);
        $selectedProvider = $selectedProviderId > 0
            ? $providersNeedingProvinceHub->firstWhere('id', $selectedProviderId)
            : null;

        return view('Admin.Hubs.index', [
            'hubs' => $hubs,
            'locationChanges' => $locationChanges,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
            'totalCount' => $totalCount,
            'status' => $status,
            'type' => $type,
            'search' => $search,
            'perPage' => $perPage,
            'providersNeedingProvinceHub' => $providersNeedingProvinceHub,
            'selectedProvider' => $selectedProvider,
            'provinces' => $provinces,
        ]);
    }

    public function provisionProvinceHub(
        Request $request,
        ProviderProvinceService $provinces,
        PhilippineLocationService $locations,
    ): RedirectResponse {
        $data = $request->validate([
            'logistics_provider_id' => ['required', 'integer', 'exists:logistics_providers,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'region_code' => ['required', 'string', 'max:20'],
            'region_name' => ['nullable', 'string', 'max:120'],
            'city_municipality_code' => ['required', 'string', 'max:20'],
            'municipality_name' => ['nullable', 'string', 'max:120'],
            'barangay_code' => ['required', 'string', 'max:20'],
            'barangay_name' => ['nullable', 'string', 'max:120'],
            'street' => ['required', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'location_details' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = LogisticsProvider::query()->findOrFail($data['logistics_provider_id']);
        $admin = Auth::guard('admin')->user();

        try {
            // Pre-resolve so missing PSGC answers fail before the write.
            $locations->validateAndResolve([
                'region_code' => $data['region_code'],
                'province_code' => (string) $provider->province_code,
                'city_municipality_code' => $data['city_municipality_code'],
                'barangay_code' => $data['barangay_code'],
                'region_name' => $data['region_name'] ?? null,
                'province_name' => $provider->province_name,
                'municipality_name' => $data['municipality_name'] ?? null,
                'barangay_name' => $data['barangay_name'] ?? null,
            ]);

            $hub = $provinces->provisionProvinceHub($provider, $admin, $data);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.hubs.index', ['status' => 'approved', 'type' => 'province_hub'])
            ->with('success', "Province Hub \"{$hub->name}\" provisioned for {$provider->business_name}.");
    }

    public function changeProvince(Request $request, LogisticsProvider $logisticsProvider, ProviderProvinceService $provinces): RedirectResponse
    {
        $data = $request->validate([
            'province_code' => ['required', 'string', 'max:20'],
            'province_name' => ['nullable', 'string', 'max:120'],
            'region_code' => ['nullable', 'string', 'max:20'],
            'region_name' => ['nullable', 'string', 'max:120'],
        ]);

        $admin = Auth::guard('admin')->user();

        try {
            $provinces->changeProvinceByAdmin(
                $logisticsProvider,
                $admin,
                $data['province_code'],
                $data['province_name'] ?? null,
                $data['region_code'] ?? null,
                $data['region_name'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return back()->with(
            'success',
            "Service province for {$logisticsProvider->business_name} updated to {$logisticsProvider->fresh()->province_name}. Provision a new Province Hub when ready."
        );
    }

    public function approve(Request $request, int $hub): RedirectResponse
    {
        return DB::transaction(function () use ($hub): RedirectResponse {
            $hubModel = LogisticsHub::query()->with(['provider', 'parent'])->lockForUpdate()->findOrFail($hub);

            // Prevent duplicate action (Requirement 12)
            if ($hubModel->approval_status !== LogisticsHub::APPROVAL_PENDING) {
                return back()->with('error', "This hub has already been {$hubModel->approval_status}.");
            }

            // Station Hub parent restriction (Requirement 10)
            if ($hubModel->hub_type === LogisticsHub::STATION) {
                if ($hubModel->parent_hub_id === null) {
                    return back()->with('error', 'A Station Hub must have a parent Province Hub.');
                }

                $parent = LogisticsHub::query()->lockForUpdate()->find($hubModel->parent_hub_id);

                if (! $parent || (int) $parent->logistics_provider_id !== (int) $hubModel->logistics_provider_id) {
                    return back()->with('error', 'The parent Province Hub must belong to the same Logistics company.');
                }

                if ($parent->isArchived() || ! $parent->isLiveProvinceHub()) {
                    return back()->with('error', 'A Station Hub can only be approved under the provider\'s live Province Hub.');
                }

                if (! $parent->isApproved() || ! $parent->is_active) {
                    return back()->with('error', 'A Station Hub can only be approved if its parent Province Hub belongs to the same Logistics company and is approved and active.');
                }

                $provider = $hubModel->provider;
                if ($provider && $provider->hasVerifiedProvince()
                    && (string) $parent->province_code !== (string) $provider->province_code) {
                    return back()->with('error', 'Station Hub province must match the provider\'s verified province.');
                }
            }

            if ($hubModel->hub_type === LogisticsHub::PROVINCE) {
                $existingLive = LogisticsHub::query()
                    ->where('logistics_provider_id', $hubModel->logistics_provider_id)
                    ->where('hub_type', LogisticsHub::PROVINCE)
                    ->where('active_province_slot', 1)
                    ->whereNull('archived_at')
                    ->whereKeyNot($hubModel->id)
                    ->lockForUpdate()
                    ->exists();

                if ($existingLive) {
                    return back()->with('error', 'This provider already has a live Province Hub. Archive it before approving another.');
                }

                $provider = $hubModel->provider;
                if ($provider && $provider->hasVerifiedProvince()
                    && (string) $hubModel->province_code !== (string) $provider->province_code) {
                    return back()->with('error', 'Province Hub must match the provider\'s verified province.');
                }
            }

            $admin = Auth::guard('admin')->user();

            $attributes = [
                'approval_status' => LogisticsHub::APPROVAL_APPROVED,
                'rejection_reason' => null,
                'approved_at' => now(),
                'approved_by' => $admin?->id,
            ];

            // Province Hubs keep the existing auto-activation behaviour.
            // Station Hubs become eligible for activation but stay inactive
            // until the logistics company explicitly activates them.
            if ($hubModel->hub_type === LogisticsHub::PROVINCE) {
                $attributes['is_active'] = true;
                $attributes['active_province_slot'] = 1;
                $attributes['archived_at'] = null;
            }

            $hubModel->forceFill($attributes)->save();

            // Notify Logistics company (Requirement 11)
            if ($hubModel->provider) {
                $hubModel->provider->notify(new LogisticsHubDecisionNotification($hubModel, 'approved'));
            }

            return back()->with('success', "Hub \"{$hubModel->name}\" approved successfully.");
        });
    }

    public function reject(Request $request, int $hub): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($hub, $data): RedirectResponse {
            $hubModel = LogisticsHub::query()->with('provider')->lockForUpdate()->findOrFail($hub);

            // Prevent duplicate action (Requirement 12)
            if ($hubModel->approval_status !== LogisticsHub::APPROVAL_PENDING) {
                return back()->with('error', "This hub has already been {$hubModel->approval_status}.");
            }

            $admin = Auth::guard('admin')->user();

            $hubModel->forceFill([
                'approval_status' => LogisticsHub::APPROVAL_REJECTED,
                'is_active' => false,
                'rejection_reason' => $data['rejection_reason'],
                'rejected_at' => now(),
                'rejected_by' => $admin?->id,
                'active_province_slot' => null,
            ])->save();

            // Notify Logistics company (Requirement 11)
            if ($hubModel->provider) {
                $hubModel->provider->notify(new LogisticsHubDecisionNotification($hubModel, 'rejected', $data['rejection_reason']));
            }

            return back()->with('success', "Hub \"{$hubModel->name}\" rejected.");
        });
    }

    public function approveLocationChange(Request $request, int $change): RedirectResponse
    {
        return DB::transaction(function () use ($change): RedirectResponse {
            $locationChange = LogisticsHubLocationChange::query()
                ->with('hub.provider')
                ->lockForUpdate()
                ->findOrFail($change);

            if ($locationChange->status !== LogisticsHubLocationChange::STATUS_PENDING) {
                return back()->with('error', "This location change has already been {$locationChange->status}.");
            }

            $hub = LogisticsHub::query()->lockForUpdate()->findOrFail($locationChange->logistics_hub_id);
            $provider = $hub->provider;

            // Never allow a location change to move a hub out of the verified province.
            if ($provider && $provider->hasVerifiedProvince()
                && (string) $locationChange->province_code !== (string) $provider->province_code) {
                return back()->with('error', 'Location changes cannot alter the provider\'s verified province. Reject this request.');
            }

            $hub->forceFill([
                'region_code' => $locationChange->region_code,
                'region_name' => $locationChange->region_name,
                'province_code' => $locationChange->province_code,
                'province_name' => $locationChange->province_name,
                'city_municipality_code' => $locationChange->city_municipality_code,
                'municipality' => $locationChange->municipality,
                'barangay_code' => $locationChange->barangay_code,
                'barangay_name' => $locationChange->barangay_name,
                'street' => $locationChange->street,
                'building' => $locationChange->building,
                'landmark' => $locationChange->landmark,
                'postal_code' => $locationChange->postal_code,
            ])->save();

            $admin = Auth::guard('admin')->user();

            $locationChange->forceFill([
                'status' => LogisticsHubLocationChange::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin?->id,
                'rejection_reason' => null,
            ])->save();

            return back()->with('success', "Location change for \"{$hub->name}\" approved. The hub remains approved and keeps its operational status.");
        });
    }

    public function rejectLocationChange(Request $request, int $change): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($change, $data): RedirectResponse {
            $locationChange = LogisticsHubLocationChange::query()
                ->with('hub')
                ->lockForUpdate()
                ->findOrFail($change);

            if ($locationChange->status !== LogisticsHubLocationChange::STATUS_PENDING) {
                return back()->with('error', "This location change has already been {$locationChange->status}.");
            }

            $admin = Auth::guard('admin')->user();

            $locationChange->forceFill([
                'status' => LogisticsHubLocationChange::STATUS_REJECTED,
                'rejection_reason' => $data['rejection_reason'],
                'reviewed_at' => now(),
                'reviewed_by' => $admin?->id,
            ])->save();

            return back()->with('success', "Location change for \"{$locationChange->hub?->name}\" rejected. The approved location remains active.");
        });
    }
}

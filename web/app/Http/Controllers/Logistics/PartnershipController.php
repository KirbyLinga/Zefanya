<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Services\Logistics\ProviderHandoffSchemaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PartnershipController extends Controller
{
    public function index(Request $request, ProviderHandoffSchemaService $partnerships): View
    {
        $provider = $request->user('logistics');

        $status = (string) $request->query('status', 'all');
        $validStatuses = ['pending', 'active', 'rejected', 'revoked', 'inactive', 'all'];
        if (! in_array($status, $validStatuses, true)) {
            $status = 'all';
        }

        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $perPage = (int) $request->query('per_page', 10);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }

        $base = LogisticsProviderLink::query()
            ->where(function ($query) use ($provider): void {
                $query->where('origin_provider_id', $provider->id)
                    ->orWhere('destination_provider_id', $provider->id);
            });

        $counts = (clone $base)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $links = $base
            ->with(['originProvider', 'destinationProvider', 'approver', 'rejector', 'revoker'])
            ->when($status !== 'all', fn ($query) => $query->where('status', strtoupper($status)))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->whereHas('originProvider', fn ($providerQuery) => $providerQuery
                        ->where('business_name', 'like', "%{$search}%"))
                        ->orWhereHas('destinationProvider', fn ($providerQuery) => $providerQuery
                            ->where('business_name', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return view('Logistics.Partnerships.index', [
            'provider' => $provider,
            'links' => $links,
            'eligibleDestinations' => $partnerships->eligibleDestinations($provider),
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
            'perPage' => $perPage,
        ]);
    }

    public function store(Request $request, ProviderHandoffSchemaService $partnerships): RedirectResponse
    {
        $provider = $request->user('logistics');

        $data = $request->validate([
            'destination_provider_id' => ['required', 'integer', 'exists:logistics_providers,id'],
        ]);

        $destination = LogisticsProvider::query()->findOrFail($data['destination_provider_id']);

        try {
            $partnerships->requestLink($provider, $destination);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('logistics.partnerships.index')
            ->with('success', "Partnership request sent to {$destination->business_name}. It becomes active only after they accept and Admin approves it.");
    }

    public function accept(
        Request $request,
        LogisticsProviderLink $link,
        ProviderHandoffSchemaService $partnerships,
    ): RedirectResponse {
        $provider = $request->user('logistics');
        $this->assertDestination($provider, $link);

        try {
            $partnerships->accept($link, $provider);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with(
            'success',
            $link->fresh()->isActive()
                ? "Partnership with {$link->originProvider?->business_name} is now active."
                : 'Partnership accepted. It is now waiting for Admin approval.'
        );
    }

    public function reject(
        Request $request,
        LogisticsProviderLink $link,
        ProviderHandoffSchemaService $partnerships,
    ): RedirectResponse {
        $provider = $request->user('logistics');
        $this->assertDestination($provider, $link);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $partnerships->reject($link, $provider, $data['rejection_reason']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return back()->with('success', 'Partnership request rejected. The origin provider will see your reason.');
    }

    private function assertDestination(LogisticsProvider $provider, LogisticsProviderLink $link): void
    {
        abort_unless(
            (int) $link->destination_provider_id === (int) $provider->getKey(),
            403,
            'Only the destination provider can respond to this partnership request.'
        );
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoffException;
use App\Services\ParcelHandoffService;
use App\Services\ProviderHandoffSchemaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PartnershipController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        $validStatuses = ['pending', 'active', 'rejected', 'revoked', 'inactive', 'all'];
        if (! in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }

        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 25;
        }

        $counts = LogisticsProviderLink::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $links = LogisticsProviderLink::query()
            ->with(['originProvider', 'destinationProvider', 'approver', 'rejector', 'revoker'])
            ->when($status !== 'all', fn ($query) => $query->where('status', strtoupper($status)))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->whereHas('originProvider', fn ($providerQuery) => $providerQuery
                        ->where('business_name', 'like', "%{$search}%")
                        ->orWhere('province_name', 'like', "%{$search}%"))
                        ->orWhereHas('destinationProvider', fn ($providerQuery) => $providerQuery
                            ->where('business_name', 'like', "%{$search}%")
                            ->orWhere('province_name', 'like', "%{$search}%"));
                });
            })
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $handoffExceptions = ParcelHandoffException::query()
            ->with(['sellerOrder.order', 'originProvider', 'resolver'])
            ->latest('id')
            ->paginate(10, ['*'], 'exceptions_page')
            ->withQueryString();

        return view('Admin.Partnerships.index', [
            'links' => $links,
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
            'perPage' => $perPage,
            'handoffExceptions' => $handoffExceptions,
            'openExceptionCount' => ParcelHandoffException::query()->where('status', ParcelHandoffException::OPEN)->count(),
        ]);
    }

    public function approve(
        Request $request,
        LogisticsProviderLink $link,
        ProviderHandoffSchemaService $partnerships,
    ): RedirectResponse {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        try {
            $partnerships->approve($link, $request->user('admin'), (int) $data['priority']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return back()->with(
            'success',
            $link->fresh()->isActive()
                ? 'Partnership approved and active.'
                : 'Partnership approved. It activates after the destination provider accepts.'
        );
    }

    public function resolveException(Request $request, ParcelHandoffException $exception, ParcelHandoffService $handoffs): RedirectResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);
        $handoffs->resolveException($exception, $request->user('admin'), $data['resolution_note']);

        return back()->with('success', 'Handoff exception resolved. Parcel custody and delivery status are unchanged.');
    }

    public function updatePriority(
        Request $request,
        LogisticsProviderLink $link,
        ProviderHandoffSchemaService $partnerships,
    ): RedirectResponse {
        $data = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        try {
            $partnerships->setPriority($link, $request->user('admin'), (int) $data['priority']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return back()->with('success', 'Partnership priority updated.');
    }

    public function revoke(
        Request $request,
        LogisticsProviderLink $link,
        ProviderHandoffSchemaService $partnerships,
    ): RedirectResponse {
        $data = $request->validate([
            'revocation_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $partnerships->revoke($link, $request->user('admin'), $data['revocation_reason']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return back()->with('success', 'Partnership approval revoked.');
    }
}

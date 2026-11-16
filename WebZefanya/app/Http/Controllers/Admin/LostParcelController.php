<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelLossCase;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin's lost parcels: who is liable, the manual compensation confirmation,
 * audited adjustments, and what to do with a parcel found afterwards. Nothing
 * here moves money, restores stock or reopens an order.
 */
class LostParcelController extends Controller
{
    public function __construct(private readonly ParcelLossService $losses) {}

    public function index(Request $request): View
    {
        abort_unless(ParcelLossService::available(), 404);
        $this->authorizeAdmin($request);

        $status = in_array($request->query('status'), ['review', 'owed', 'paid', 'found'], true) ? $request->query('status') : 'all';
        $cases = ParcelLossCase::query()
            ->with(['sellerOrder:id,tracking_number,order_id', 'seller:id,business_name', 'provider:id,business_name', 'adjustments'])
            ->when($status === 'review', fn ($q) => $q->where('compensation_status', ParcelLossCase::REVIEW_REQUIRED))
            ->when($status === 'owed', fn ($q) => $q->where('compensation_status', ParcelLossCase::PENDING))
            ->when($status === 'paid', fn ($q) => $q->where('compensation_status', ParcelLossCase::PAID))
            ->when($status === 'found', fn ($q) => $q->whereNotNull('recovered_at'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('Admin.LostParcels.index', [
            'cases' => $cases,
            'status' => $status,
            'providers' => LogisticsProvider::query()->where('status', 'approved')->whereNull('archived_at')->orderBy('business_name')->get(['id', 'business_name']),
            'counts' => [
                'review' => ParcelLossCase::query()->where('compensation_status', ParcelLossCase::REVIEW_REQUIRED)->count(),
                'owed' => ParcelLossCase::query()->where('compensation_status', ParcelLossCase::PENDING)->count(),
                'found' => ParcelLossCase::query()->whereNotNull('recovered_at')->whereNull('disposition')->count(),
            ],
        ]);
    }

    public function assign(Request $request, ParcelLossCase $case): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['liable_provider_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $this->losses->assignLiability($case, $request->user('admin'), (int) $data['liable_provider_id'], $data['reason']);

        return back()->with('success', 'Liability recorded. The company has been told what it owes the Seller.');
    }

    public function pay(Request $request, ParcelLossCase $case): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100'], 'paid_on' => ['required', 'date_format:Y-m-d']]);
        $this->losses->recordCompensationPayment($case, $request->user('admin'), $data['reference'], $data['paid_on']);

        return back()->with('success', 'Recorded: the Seller was paid the merchandise value. No money moved through Zefanya.');
    }

    public function adjust(Request $request, ParcelLossCase $case): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['amount' => ['required', 'regex:/^-?\d{1,7}(\.\d{1,2})?$/'], 'reason' => ['required', 'string', 'max:1000']]);
        $minor = (int) round((float) $data['amount'] * 100);
        $this->losses->recordAdjustment($case, $request->user('admin'), $minor, $data['reason']);

        return back()->with('success', 'Adjustment recorded. The paid amount itself is unchanged.');
    }

    public function disposition(Request $request, ParcelLossCase $case): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['disposition' => ['required', 'string', 'max:30'], 'note' => ['required', 'string', 'max:1000']]);
        $this->losses->decideDisposition($case, $request->user('admin'), $data['disposition'], $data['note']);

        return back()->with('success', 'Decision recorded. The order stays closed as lost and stock is unchanged.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(in_array($request->user('admin')?->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
    }
}

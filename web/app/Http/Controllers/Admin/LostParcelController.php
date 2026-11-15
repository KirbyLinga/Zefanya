<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelLossCase;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LostParcelController extends Controller
{
    public function index(Request $request): View
    {
        ParcelLossService::authorize($request->user('admin'));
        abort_unless(ParcelLossService::available(), 503);

        return view('Admin.lost-parcels', [
            'cases' => ParcelLossCase::query()->with(['sellerOrder', 'provider'])->latest('id')->paginate(25),
            'providers' => LogisticsProvider::query()->where('status', 'approved')->whereNull('archived_at')->orderBy('business_name')->get(),
        ]);
    }

    public function assign(Request $request, ParcelLossCase $case, ParcelLossService $loss): RedirectResponse
    {
        ParcelLossService::authorize($request->user('admin'));
        $data = $request->validate(['logistics_provider_id' => 'required|integer|min:1', 'reason' => 'required|string|max:1000']);
        $loss->assignLiability($case, $request->user('admin'), (int) $data['logistics_provider_id'], $data['reason']);

        return back()->with('success', 'Liability recorded.');
    }

    public function pay(Request $request, ParcelLossCase $case, ParcelLossService $loss): RedirectResponse
    {
        ParcelLossService::authorize($request->user('admin'));
        $data = $request->validate(['reference' => 'required|string|max:120', 'date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $loss->recordCompensationPayment($case, $request->user('admin'), $data['reference'], $data['date']);

        return back()->with('success', 'External payment evidence recorded.');
    }

    public function adjust(Request $request, ParcelLossCase $case, ParcelLossService $loss): RedirectResponse
    {
        ParcelLossService::authorize($request->user('admin'));
        $data = $request->validate(['amount' => ['required', 'regex:/\A-?\d{1,10}(?:\.\d{1,2})?\z/'], 'reason' => 'required|string|max:1000']);
        $value = (string) $data['amount'];
        [$units, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $amount = (int) $units * 100 + (int) str_pad($fraction, 2, '0');
        $loss->recordAdjustment($case, $request->user('admin'), str_starts_with($value, '-') ? -$amount : $amount, $data['reason']);

        return back()->with('success', 'Compensation adjustment recorded.');
    }

    public function disposition(Request $request, ParcelLossCase $case, ParcelLossService $loss): RedirectResponse
    {
        ParcelLossService::authorize($request->user('admin'));
        $data = $request->validate(['disposition' => 'required|in:return_to_seller,written_off', 'note' => 'required|string|max:1000']);
        $loss->recordDisposition($case, $request->user('admin'), $data['disposition'], $data['note']);

        return back()->with('success', 'Disposition recorded.');
    }
}

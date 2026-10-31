<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function index()
    {
        $returns = ParcelReturn::query()->with(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'sellerOrder.manifestItems.manifest', 'sellerOrder.parcelHandoffs', 'sellerOrder.parcelHandoffExceptions', 'originHub', 'originProvider', 'events'])->latest('id')->paginate(20)->withQueryString();
        $hubs = LogisticsHub::query()->operational()->with('provider')->orderBy('name')->get();

        return view('Admin.Returns.index', compact('returns', 'hubs'));
    }

    public function verifyOrigin(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['origin_hub_id' => ['required', 'integer'], 'barangay_code' => ['required', 'string', 'max:20'], 'address' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:1000']]);
        $service->verifyOrigin($request->user('admin'), $return, (int) $data['origin_hub_id'], $data['barangay_code'], $data['address'], $data['evidence']);

        return back()->with('success', 'Original pickup location verified. Reverse transport still requires normal authorization and QR receiving.');
    }

    public function resolve(Request $request, ParcelReturn $return, ParcelHandoffService $handoffs, ParcelReturnService $service)
    {
        $service->admin($request->user('admin'));
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);
        foreach ($return->sellerOrder->parcelHandoffExceptions()->where('status', 'OPEN')->get() as $exception) {
            $handoffs->resolveException($exception, $request->user('admin'), $data['resolution_note']);
        }
        $return->forceFill(['exception_reason' => null])->save();

        return back()->with('success', 'Exception resolution recorded. Custody, Seller receipt and payment states were not changed.');
    }

    public function resolveDispute(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:upheld,overturned'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'outcome.required' => 'Choose whether the rejection stands or the return goes ahead.',
            'note.required' => 'The resolution note must be 10 to 1000 characters.',
            'note.min' => 'The resolution note must be 10 to 1000 characters.',
            'note.max' => 'The resolution note must be 10 to 1000 characters.',
        ]);
        $service->resolveDispute($return, $request->user('admin'), $data['outcome'], $data['note']);

        return back()->with('success', $data['outcome'] === 'upheld'
            ? 'The seller’s rejection stands. The dispute is closed.'
            : 'The return goes ahead. The seller’s rejection was overturned.');
    }

    public function recordRefund(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:255'],
        ], [
            'reference.required' => 'A refund reference of at most 255 characters is required.',
        ]);
        $service->recordRefund($return, $request->user('admin'), $data['reference']);

        return back()->with('success', 'Refund recorded. Pending earnings and commission for this shipment have been released.');
    }

    public function stock(Request $request, ParcelReturn $return, SellerOrderStatusService $status)
    {
        try {
            $status->releaseVerifiedReturnStock($return->sellerOrder, $request->user('admin'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['stock' => $exception->getMessage()]);
        }

        return back()->with('success', 'Verified resellable goods restored once with stock-ledger entries.');
    }
}

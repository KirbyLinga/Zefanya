<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $returns = ParcelReturn::query()->whereHas('sellerOrder', fn ($q) => $q->where('seller_id', $request->user('seller')->id))
            ->with(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'originHub', 'events'])->latest('id')->paginate(20)->withQueryString();

        return view('Seller.Returns.index', compact('returns'));
    }

    public function approve(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeForUser($request->user('seller'), 'view', $return->sellerOrder);
        $service->approveBySeller($return, $request->user('seller'));

        return back()->with('success', 'Return request approved. The parcel can now start its way back to you.');
    }

    public function reject(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeForUser($request->user('seller'), 'view', $return->sellerOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:300']], [
            'reason.required' => 'Enter a reason for rejecting this return.',
            'reason.min' => 'The rejection reason must be at least 10 characters.',
            'reason.max' => 'The rejection reason may not be more than 300 characters.',
        ]);
        $service->rejectBySeller($return, $request->user('seller'), $data['reason']);

        return back()->with('success', 'Return request rejected. The buyer has been told why.');
    }

    /** Buyer evidence lives on the private disk; only the Seller of this parcel may fetch it. */
    public function photo(Request $request, ParcelReturn $return, int $index): StreamedResponse
    {
        $this->authorizeForUser($request->user('seller'), 'view', $return->sellerOrder);
        $path = $return->photos[$index] ?? null;
        abort_if($path === null, 404);

        return Storage::disk('local')->response($path);
    }

    public function verify(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeForUser($request->user('seller'), 'view', $return->sellerOrder);
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:64'], 'handover_code' => ['required', 'string', 'max:64'], 'condition' => ['required', Rule::in(['resellable', 'damaged', 'pending_review'])]]);
        $token = $service->verifySeller($return, $request->user('seller'), $data['tracking_number'], $data['handover_code'], $data['condition']);

        return back()->with('return_verification', ['id' => $return->id, 'token' => $token, 'condition' => $data['condition']])->with('success', 'Physical handover verified. Confirm receipt after checking the parcel.');
    }

    public function receive(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeForUser($request->user('seller'), 'view', $return->sellerOrder);
        $data = $request->validate(['verification_token' => ['required', 'string', 'max:128'], 'condition' => ['required', Rule::in(['resellable', 'damaged', 'pending_review'])]]);
        $service->receiveBySeller($return, $request->user('seller'), $data['condition'], $data['verification_token']);

        return back()->with('success', 'Physical receipt recorded. Inventory remains held unless the approved restoration policy permits it.');
    }
}

<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelReturn;
use App\Services\ParcelReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $returns = ParcelReturn::query()->whereHas('sellerOrder', fn ($q) => $q->where('seller_id', $request->user('seller')->id))
            ->with(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'originHub', 'events'])->latest('id')->paginate(20)->withQueryString();

        return view('Seller.Returns.index', compact('returns'));
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

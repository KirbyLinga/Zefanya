<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryProof;
use App\Support\DeliveryProofImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams private proof-of-delivery evidence to the Logistics company that
 * handled the parcel. Other providers see a 404 (never a 403 that would leak
 * the existence of another company's evidence).
 */
class DeliveryProofController extends Controller
{
    public function show(Request $request, DeliveryProof $deliveryProof): StreamedResponse
    {
        $provider = $request->user('logistics');

        $courier = $deliveryProof->courier;

        abort_if(
            $courier === null || (int) $courier->logistics_provider_id !== (int) $provider->getKey(),
            404,
        );

        return DeliveryProofImage::response($deliveryProof->photo_path);
    }
}

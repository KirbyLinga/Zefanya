<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryProof;
use App\Support\DeliveryProofImage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/rider/proofs/{deliveryProof} — the proof-of-delivery photo, streamed
 * only to the rider who submitted it (the same rule and the same private-disk
 * response as the Rider web route).
 */
class ProofController extends Controller
{
    public function show(Request $request, DeliveryProof $deliveryProof): StreamedResponse
    {
        $courier = $request->user()?->courier;
        abort_if($courier === null, 403);
        abort_if(
            (int) $deliveryProof->courier_id !== (int) $courier->id,
            403,
            'This proof of delivery does not belong to you.',
        );

        return DeliveryProofImage::response($deliveryProof->photo_path);
    }
}

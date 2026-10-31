<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams private proof-of-delivery evidence to the rider who submitted it.
 *
 * The file lives on the private `local` disk, so it has no public URL; access
 * is authorized by ownership of the linked Courier.
 */
class DeliveryProofController extends Controller
{
    public function show(Request $request, DeliveryProof $deliveryProof): StreamedResponse
    {
        $courier = $request->user('rider')?->courier;

        abort_if($courier === null, 403);

        abort_if(
            (int) $deliveryProof->courier_id !== (int) $courier->id,
            403,
            'This proof of delivery does not belong to you.',
        );

        abort_unless(Storage::disk('local')->exists($deliveryProof->photo_path), 404);

        return Storage::disk('local')->response($deliveryProof->photo_path, null, [
            'Content-Type' => $deliveryProof->photo_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

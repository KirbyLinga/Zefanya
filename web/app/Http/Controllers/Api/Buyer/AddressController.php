<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\StoreBuyerAddressRequest;
use App\Http\Requests\Buyer\UpdateBuyerAddressRequest;
use App\Http\Resources\Buyer\BuyerAddressResource;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AddressController extends Controller
{
    /**
     * GET /api/buyer/addresses
     * GET /api/v1/buyer/addresses
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer, 403, 'Only buyers have saved delivery addresses.');

        $addresses = $buyer->addresses()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return BuyerAddressResource::collection($addresses);
    }

    /**
     * POST /api/buyer/addresses
     * POST /api/v1/buyer/addresses
     */
    public function store(StoreBuyerAddressRequest $request): JsonResponse
    {
        $buyer = $request->user();
        $validated = $request->validated();

        $cleanPhone = preg_replace('/[\s-]/', '', $validated['phone']);
        if (str_starts_with($cleanPhone, '+63')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }
        $validated['phone'] = $cleanPhone;

        $hasAddresses = $buyer->addresses()->exists();
        $isDefault = $validated['is_default'] ?? (! $hasAddresses);

        $address = $buyer->addresses()->create(array_merge($validated, [
            'is_default' => false, // Set initially to false
        ]));

        if ($isDefault || ! $hasAddresses) {
            $address->makeDefault();
        }

        return (new BuyerAddressResource($address->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/buyer/addresses/{address}
     * GET /api/v1/buyer/addresses/{address}
     */
    public function show(Request $request, BuyerAddress $address): BuyerAddressResource
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer && $address->buyer_id === $buyer->id, 403, 'Unauthorized.');

        return new BuyerAddressResource($address);
    }

    /**
     * PUT /api/buyer/addresses/{address}
     * PUT /api/v1/buyer/addresses/{address}
     */
    public function update(UpdateBuyerAddressRequest $request, BuyerAddress $address): BuyerAddressResource
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer && $address->buyer_id === $buyer->id, 403, 'Unauthorized.');

        $validated = $request->validated();
        if (isset($validated['phone'])) {
            $cleanPhone = preg_replace('/[\s-]/', '', $validated['phone']);
            if (str_starts_with($cleanPhone, '+63')) {
                $cleanPhone = '0' . substr($cleanPhone, 3);
            }
            $validated['phone'] = $cleanPhone;
        }

        $makeDefault = !empty($validated['is_default']);
        unset($validated['is_default']);

        $address->update($validated);

        if ($makeDefault) {
            $address->makeDefault();
        }

        return new BuyerAddressResource($address->fresh());
    }

    /**
     * DELETE /api/buyer/addresses/{address}
     * DELETE /api/v1/buyer/addresses/{address}
     */
    public function destroy(Request $request, BuyerAddress $address): JsonResponse
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer && $address->buyer_id === $buyer->id, 403, 'Unauthorized.');

        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $first = $buyer->addresses()->latest('id')->first();
            $first?->makeDefault();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Address removed from your address book.',
        ]);
    }

    /**
     * PATCH /api/buyer/addresses/{address}/default
     * PATCH /api/v1/buyer/addresses/{address}/default
     */
    public function makeDefault(Request $request, BuyerAddress $address): BuyerAddressResource
    {
        $buyer = $request->user();
        abort_unless($buyer instanceof Buyer && $address->buyer_id === $buyer->id, 403, 'Unauthorized.');

        $address->makeDefault();

        return new BuyerAddressResource($address->fresh());
    }
}

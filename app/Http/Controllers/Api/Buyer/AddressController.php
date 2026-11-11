<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Buyer\BuyerAddress;
use App\Services\Buyer\BuyerAddressBook;
use App\Support\BuyerAddressRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer mobile address book. Same rules (BuyerAddressRules) and the same
 * service (BuyerAddressBook: buyer row lock, address limit, single default,
 * default cannot be deleted) as the buyer web account page.
 */
class AddressController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()->orderByDesc('is_default')->orderBy('id')->get();

        return $this->success('Addresses retrieved.', $addresses->map(fn (BuyerAddress $a) => self::present($a))->values());
    }

    public function store(Request $request, BuyerAddressBook $book): JsonResponse
    {
        $data = $request->validate(BuyerAddressRules::rules($request->input('address_mode')));
        $address = $book->save($request->user(), $data, null, $request->boolean('is_default'));

        return $this->success('Address saved.', self::present($address), 201);
    }

    public function update(Request $request, BuyerAddress $address, BuyerAddressBook $book): JsonResponse
    {
        $this->own($request, $address);
        $data = $request->validate(BuyerAddressRules::rules($request->input('address_mode')));
        $address = $book->save($request->user(), $data, $address, $request->boolean('is_default') || $address->is_default);

        return $this->success('Address updated.', self::present($address));
    }

    public function makeDefault(Request $request, BuyerAddress $address, BuyerAddressBook $book): JsonResponse
    {
        $this->own($request, $address);

        return $this->success('Default address updated.', self::present($book->makeDefault($request->user(), $address)));
    }

    public function destroy(Request $request, BuyerAddress $address, BuyerAddressBook $book): JsonResponse
    {
        $this->own($request, $address);
        $book->delete($request->user(), $address);

        return $this->success('Address deleted.');
    }

    private function own(Request $request, BuyerAddress $address): void
    {
        abort_unless((int) $address->buyer_id === (int) $request->user()->getKey(), 404);
    }

    /** @return array<string, mixed> */
    public static function present(BuyerAddress $address): array
    {
        return [
            'id' => $address->id,
            'is_default' => (bool) $address->is_default,
            'address_mode' => $address->address_mode,
            'province' => $address->province_code,
            'province_name' => $address->province_name,
            'municipality' => $address->municipality_code,
            'municipality_name' => $address->municipality_name,
            'barangay' => $address->barangay_code,
            'barangay_name' => $address->barangay_name,
            'street' => $address->street,
            'house_number' => $address->house_number,
            'address_detail' => $address->address_detail,
            'postal_code' => $address->postal_code,
        ];
    }
}

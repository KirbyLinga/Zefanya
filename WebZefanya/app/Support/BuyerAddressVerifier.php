<?php

namespace App\Support;

use App\Services\Shared\PhilippineLocationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Confirms that a Buyer's province, municipality and barangay codes form one
 * real PSGC chain (barangay inside municipality inside province), and replaces
 * the submitted names with the official ones. Codes decide coverage and
 * routing and names are printed on waybills, so they must not disagree.
 *
 * When the PSGC API is down, PhilippineLocationService serves the last good
 * list it saved, so addresses that were valid before the outage still pass.
 * With no saved list at all, the address is refused with a "try again" message
 * rather than saved unchecked. Addresses already saved are never re-checked,
 * so checkout and existing orders are unaffected by an outage.
 *
 * Typed-in ("manual") entries are refused: every new or edited address must
 * carry PSGC codes, so this check always applies.
 */
final class BuyerAddressVerifier
{
    private const NCR = '130000000';

    public const UNAVAILABLE_MESSAGE = 'We could not verify this address right now. Please try again in a few minutes.';

    public function __construct(private readonly PhilippineLocationService $psgc) {}

    /**
     * @param  array<string, mixed>  $data  validated address fields (province, municipality, barangay and their *_name)
     * @return array<string, mixed> $data with the official province_name, municipality_name and barangay_name
     *
     * @throws ValidationException
     */
    public function verify(array $data): array
    {
        // Every new or edited address must carry official codes; there is no unchecked mode.
        if (($data['address_mode'] ?? null) !== 'api') {
            throw ValidationException::withMessages(['address_mode' => 'Choose your province, city or municipality and barangay from the lists. Typed-in addresses cannot be verified.']);
        }

        $provinceCode = (string) ($data['province'] ?? '');
        $municipalityCode = (string) ($data['municipality'] ?? '');
        $barangayCode = (string) ($data['barangay'] ?? '');

        try {
            $province = $provinceCode === self::NCR
                ? ['code' => self::NCR, 'name' => 'Metro Manila']
                : collect($this->psgc->getProvinces())->firstWhere('code', $provinceCode);

            if ($province === null) {
                throw ValidationException::withMessages(['province' => 'Select a valid province.']);
            }

            $municipality = collect($this->psgc->getCitiesMunicipalities($provinceCode))->firstWhere('code', $municipalityCode);

            if ($municipality === null) {
                throw ValidationException::withMessages(['municipality' => 'The city/municipality does not belong to the selected province.']);
            }

            $barangay = collect($this->psgc->getBarangays($municipalityCode))->firstWhere('code', $barangayCode);

            if ($barangay === null) {
                throw ValidationException::withMessages(['barangay' => 'The barangay does not belong to the selected city/municipality.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Buyer address could not be verified against PSGC: '.$e->getMessage());

            throw ValidationException::withMessages(['address' => self::UNAVAILABLE_MESSAGE]);
        }

        return [
            ...$data,
            'province_name' => $province['name'],
            'municipality_name' => $municipality['name'],
            'barangay_name' => $barangay['name'],
        ];
    }
}

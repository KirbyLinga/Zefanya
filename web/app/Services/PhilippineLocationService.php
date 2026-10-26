<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PhilippineLocationService
{
    private string $baseUrl;

    private int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.psgc.base_url', 'https://psgc.gitlab.io/api'), '/');
        $this->cacheTtl = (int) config('services.psgc.cache_ttl', 86400);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getRegions(): array
    {
        return Cache::remember('psgc:regions', $this->cacheTtl, function (): array {
            $response = Http::timeout(5)->get("{$this->baseUrl}/regions/");
            if (! $response->successful()) {
                throw new \RuntimeException('PSGC regions API returned status '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException('PSGC regions API returned invalid data');
            }

            usort($data, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_map(fn (array $item): array => [
                'code' => (string) ($item['code'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ], $data);
        });
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getProvinces(?string $regionCode = null): array
    {
        $cacheKey = 'psgc:provinces:'.($regionCode ?? 'all');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($regionCode): array {
            // NCR does not have formal provinces; Metro Manila acts as the province level.
            if ($regionCode === '130000000') {
                return [
                    ['code' => '130000000', 'name' => 'Metro Manila'],
                ];
            }

            $url = $regionCode
                ? "{$this->baseUrl}/regions/{$regionCode}/provinces/"
                : "{$this->baseUrl}/provinces/";

            $response = Http::timeout(5)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('PSGC provinces API returned status '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException('PSGC provinces API returned invalid data');
            }

            // Fallback for regions without child provinces
            if ($data === [] && $regionCode) {
                $regions = $this->getRegions();
                $match = collect($regions)->firstWhere('code', $regionCode);
                if ($match) {
                    return [
                        ['code' => $regionCode, 'name' => $match['name']],
                    ];
                }
            }

            usort($data, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_map(fn (array $item): array => [
                'code' => (string) ($item['code'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ], $data);
        });
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getCitiesMunicipalities(string $provinceCode, ?string $regionCode = null): array
    {
        $cacheKey = 'psgc:cities:'.$provinceCode.':'.($regionCode ?? 'none');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($provinceCode, $regionCode): array {
            if ($provinceCode === '130000000' || $regionCode === '130000000') {
                $url = "{$this->baseUrl}/regions/130000000/cities-municipalities/";
            } else {
                $url = "{$this->baseUrl}/provinces/{$provinceCode}/cities-municipalities/";
            }

            $response = Http::timeout(5)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('PSGC cities/municipalities API returned status '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException('PSGC cities/municipalities API returned invalid data');
            }

            usort($data, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_map(fn (array $item): array => [
                'code' => (string) ($item['code'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ], $data);
        });
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getBarangays(string $cityMunicipalityCode): array
    {
        $cacheKey = 'psgc:barangays:'.$cityMunicipalityCode;

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($cityMunicipalityCode): array {
            $url = "{$this->baseUrl}/cities-municipalities/{$cityMunicipalityCode}/barangays/";
            $response = Http::timeout(5)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('PSGC barangays API returned status '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException('PSGC barangays API returned invalid data');
            }

            usort($data, fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_map(fn (array $item): array => [
                'code' => (string) ($item['code'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ], $data);
        });
    }

    /**
     * Server-side validation of location codes and authoritative name resolution.
     * Never trusts user-submitted names unless the upstream API is unavailable.
     *
     * @param  array{
     *     region_code: string,
     *     province_code: string,
     *     city_municipality_code: string,
     *     barangay_code: string,
     *     region_name?: string|null,
     *     province_name?: string|null,
     *     municipality_name?: string|null,
     *     barangay_name?: string|null,
     * }  $input
     * @return array{
     *     region_name: string,
     *     province_name: string,
     *     municipality_name: string,
     *     barangay_name: string,
     * }
     *
     * @throws ValidationException
     */
    public function validateAndResolve(array $input): array
    {
        $regionCode = (string) ($input['region_code'] ?? '');
        $provinceCode = (string) ($input['province_code'] ?? '');
        $cityCode = (string) ($input['city_municipality_code'] ?? '');
        $barangayCode = (string) ($input['barangay_code'] ?? '');

        if ($regionCode === '' || $provinceCode === '' || $cityCode === '' || $barangayCode === '') {
            throw ValidationException::withMessages([
                'location' => 'Please select region, province, city/municipality, and barangay.',
            ]);
        }

        try {
            $regions = $this->getRegions();
            $region = collect($regions)->firstWhere('code', $regionCode);

            if ($regions !== [] && ! $region) {
                throw ValidationException::withMessages([
                    'region_code' => 'The selected region is invalid.',
                ]);
            }

            $provinces = $this->getProvinces($regionCode);
            $province = collect($provinces)->firstWhere('code', $provinceCode);

            if ($provinces !== [] && ! $province) {
                throw ValidationException::withMessages([
                    'province_code' => 'The selected province is invalid or does not belong to the selected region.',
                ]);
            }

            $cities = $this->getCitiesMunicipalities($provinceCode, $regionCode);
            $city = collect($cities)->firstWhere('code', $cityCode);

            if ($cities !== [] && ! $city) {
                throw ValidationException::withMessages([
                    'city_municipality_code' => 'The selected city/municipality is invalid or does not belong to the selected province.',
                ]);
            }

            $barangays = $this->getBarangays($cityCode);
            $barangay = collect($barangays)->firstWhere('code', $barangayCode);

            if ($barangays !== [] && ! $barangay) {
                throw ValidationException::withMessages([
                    'barangay_code' => 'The selected barangay is invalid or does not belong to the selected city/municipality.',
                ]);
            }

            return [
                'region_name' => $region['name'] ?? trim((string) ($input['region_name'] ?? '')),
                'province_name' => $province['name'] ?? trim((string) ($input['province_name'] ?? '')),
                'municipality_name' => $city['name'] ?? trim((string) ($input['municipality_name'] ?? '')),
                'barangay_name' => $barangay['name'] ?? trim((string) ($input['barangay_name'] ?? '')),
            ];
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('PSGC API validation failed or timed out: '.$e->getMessage());

            throw ValidationException::withMessages([
                'location' => 'Location validation service is currently unavailable. Cannot verify PSGC codes. Please try again later.',
            ]);
        }
    }
}

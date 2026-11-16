<?php

namespace Database\Seeders\Demo;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Services\Buyer\BuyerAddressBook;
use App\Services\Seller\SellerShippingProviderService;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Buyers and Sellers for the six demo areas, with complete profiles.
 *
 * Four Sellers and four Buyers per area, placed so every delivery scenario
 * has a pair: a Buyer in a Seller's own barangay, one in another barangay of
 * the same municipality, and ones in a different municipality. Handles are
 * letters and digits only (sellerlaguna01, buyercebu03). Existing accounts
 * are found by email and left as they are.
 *
 * Logistics companies and Riders come from the coverage and fleet seeders;
 * transport Couriers are records managed by Logistics and have no account.
 */
class DemoRoleAccountsSeeder extends Seeder
{
    /** Approximate postal zone per area; PSGC carries no ZIP codes. */
    private const POSTAL = ['laguna' => '4027', 'bulacan' => '3000', 'metro' => '1100', 'batangas' => '4200', 'davao' => '8000', 'cebu' => '6000'];

    /** @var array{sellers: int, buyers: int} */
    public array $created = ['sellers' => 0, 'buyers' => 0];

    /**
     * Buyer whose delivery address is outside every active provider's coverage.
     * Alaminos, Laguna is not one of the three anchor municipalities covered by
     * Demo Laguna Express, and no other provider serves Laguna barangays.
     */
    public const UNSUPPORTED_BUYER = [
        'handle' => 'buyerlaguna05',
        'area' => 'laguna',
        'province_code' => '043400000',
        'province_name' => 'Laguna',
        'municipality_code' => '043401000',
        'municipality_name' => 'Alaminos',
        'barangay_code' => '043401001',
        'barangay_name' => 'Del Carmen',
        'street' => '25 Demo Delivery Street',
        'postal_code' => '4027',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $areas = DemoNetwork::areaKeys();

            foreach (json_decode((string) file_get_contents(database_path('seeders/data/demo/shops.json')), true, flags: JSON_THROW_ON_ERROR) as $index => $shop) {
                if (in_array($shop['area'], $areas, true)) {
                    $this->seller($shop, $index);
                }
            }

            foreach ($areas as $area) {
                $this->buyers($area, (int) array_search($area, DemoNetwork::allAreaKeys(), true));
            }

            if (in_array(self::UNSUPPORTED_BUYER['area'], $areas, true)) {
                $this->unsupportedBuyer();
            }
        });
    }

    /** @param  array<string, mixed>  $shop */
    private function seller(array $shop, int $index): void
    {
        $email = DemoNetwork::email($shop['handle']);
        $seller = Seller::query()->where('email', $email)->first();

        if ($seller === null) {
            $municipality = DemoNetwork::anchors($shop['area'])[$shop['municipality']];
            [$barangayCode, $barangayName] = $municipality['barangays'][$shop['barangay']];
            $area = DemoNetwork::psgc()[$shop['area']];
            [$first, $last] = DemoNetwork::personName(200 + $index);
            $theme = $this->theme($shop['theme']);

            $seller = Seller::factory()->create([
                'email' => $email,
                'password' => DemoNetwork::passwordHash(),
                'first_name' => $first,
                'last_name' => $last,
                'sex' => $index % 2 === 0 ? 'male' : 'female',
                'contact_no' => DemoNetwork::phone(4, $index + 1),
                'business_name' => $shop['name'],
                'store_name_key' => hash('sha256', Seller::normalizedStoreName($shop['name'])),
                'store_description' => $shop['description'],
                'contact_email' => $email,
                'seller_type' => 'sole_proprietor',
                'region_code' => $area['region'][0],
                'address_mode' => 'manual',
                'province_code' => $area['province'][0],
                'province_name' => $area['province'][1],
                'municipality_code' => $municipality['code'],
                'municipality_name' => $municipality['name'],
                'barangay_code' => $barangayCode,
                'barangay_name' => $barangayName,
                'street' => (10 + $index).' Demo Pickup Street',
                'postal_code' => self::POSTAL[$shop['area']],
                'line_of_business_id' => DemoNetwork::categoryId($theme),
            ]);
            $this->created['sellers']++;
        }

        $this->storeImages($seller, $shop);

        // Enabling goes through the real eligibility check for the pickup barangay.
        $provider = DemoNetwork::provider($shop['area']);
        if ($provider !== null) {
            app(SellerShippingProviderService::class)->enable($seller, $provider);
        }
    }

    private function theme(string $key): string
    {
        foreach (json_decode((string) file_get_contents(database_path('seeders/data/demo/catalog.json')), true, flags: JSON_THROW_ON_ERROR)['themes'] as $theme) {
            if ($theme['key'] === $key) {
                return $theme['category'];
            }
        }

        return 'Electronics and Gadgets';
    }

    /** Logo and banner, stored where the Store Profile page stores uploads. */
    private function storeImages(Seller $seller, array $shop): void
    {
        $disk = Storage::disk('public');
        $dir = database_path('seeders/data/demo/images');

        foreach (['logo' => ['logos/'.$shop['handle'].'.png', 'store_logo_path'], 'banner' => ['banners/'.$shop['theme'].'.jpg', 'store_banner_path']] as $type => [$file, $column]) {
            if (filled($seller->{$column}) && $disk->exists($seller->{$column})) {
                continue;
            }

            if (! is_file($dir.'/'.$file)) {
                continue;
            }

            $path = $disk->putFile('seller-'.$type.'s/'.$seller->id, new File($dir.'/'.$file));
            $seller->forceFill([$column => $path])->saveQuietly();
        }
    }

    private function buyers(string $area, int $areaIndex): void
    {
        $anchors = DemoNetwork::anchors($area);
        $psgc = DemoNetwork::psgc()[$area];
        // [municipality index, barangay index]: same barangay as seller 1, another barangay of
        // the same municipality, and two in different municipalities.
        $places = [[0, 0], [0, 1], [1, 2], [2, 1]];

        foreach ($places as $n => [$m, $b]) {
            $handle = 'buyer'.$area.sprintf('%02d', $n + 1);
            $email = DemoNetwork::email($handle);
            $buyer = Buyer::query()->where('email', $email)->first();

            if ($buyer === null) {
                [$first, $last] = DemoNetwork::personName(400 + $areaIndex * 4 + $n);
                [$barangayCode, $barangayName] = $anchors[$m]['barangays'][$b];
                $address = [
                    'address_mode' => 'manual',
                    'province_code' => $psgc['province'][0],
                    'province_name' => $psgc['province'][1],
                    'municipality_code' => $anchors[$m]['code'],
                    'municipality_name' => $anchors[$m]['name'],
                    'barangay_code' => $barangayCode,
                    'barangay_name' => $barangayName,
                    'street' => (20 + $n).' Demo Delivery Street',
                    'postal_code' => self::POSTAL[$area],
                ];

                $buyer = Buyer::factory()->create([
                    'email' => $email,
                    'password' => DemoNetwork::passwordHash(),
                    'first_name' => $first,
                    'last_name' => $last,
                    'sex' => $n % 2 === 0 ? 'female' : 'male',
                    'contact_no' => DemoNetwork::phone(3, $areaIndex * 4 + $n + 1),
                ] + $address);

                app(BuyerAddressBook::class)->save($buyer, [
                    'address_mode' => 'manual',
                    'province' => $address['province_code'],
                    'province_name' => $address['province_name'],
                    'municipality' => $address['municipality_code'],
                    'municipality_name' => $address['municipality_name'],
                    'barangay' => $address['barangay_code'],
                    'barangay_name' => $address['barangay_name'],
                    'street' => $address['street'],
                    'postal_code' => $address['postal_code'],
                ], asDefault: true);
                $this->created['buyers']++;
            }

            $this->avatar($buyer, $handle);
        }
    }

    /** Create the buyer whose address is outside all provider coverage. Idempotent. */
    private function unsupportedBuyer(): void
    {
        $email = DemoNetwork::email(self::UNSUPPORTED_BUYER['handle']);
        $buyer = Buyer::query()->where('email', $email)->first();

        if ($buyer === null) {
            $first = 'Lualhati';
            $last = 'Alaminos';

            $buyer = Buyer::factory()->create([
                'email' => $email,
                'password' => DemoNetwork::passwordHash(),
                'first_name' => $first,
                'last_name' => $last,
                'sex' => 'female',
                'contact_no' => DemoNetwork::phone(3, 99),
                'address_mode' => 'manual',
                'province_code' => self::UNSUPPORTED_BUYER['province_code'],
                'province_name' => self::UNSUPPORTED_BUYER['province_name'],
                'municipality_code' => self::UNSUPPORTED_BUYER['municipality_code'],
                'municipality_name' => self::UNSUPPORTED_BUYER['municipality_name'],
                'barangay_code' => self::UNSUPPORTED_BUYER['barangay_code'],
                'barangay_name' => self::UNSUPPORTED_BUYER['barangay_name'],
                'street' => self::UNSUPPORTED_BUYER['street'],
                'postal_code' => self::UNSUPPORTED_BUYER['postal_code'],
            ]);

            app(BuyerAddressBook::class)->save($buyer, [
                'address_mode' => 'manual',
                'province' => self::UNSUPPORTED_BUYER['province_code'],
                'province_name' => self::UNSUPPORTED_BUYER['province_name'],
                'municipality' => self::UNSUPPORTED_BUYER['municipality_code'],
                'municipality_name' => self::UNSUPPORTED_BUYER['municipality_name'],
                'barangay' => self::UNSUPPORTED_BUYER['barangay_code'],
                'barangay_name' => self::UNSUPPORTED_BUYER['barangay_name'],
                'street' => self::UNSUPPORTED_BUYER['street'],
                'postal_code' => self::UNSUPPORTED_BUYER['postal_code'],
            ], asDefault: true);

            $this->created['buyers']++;
        }

        $this->avatar($buyer, self::UNSUPPORTED_BUYER['handle']);
    }

    /** The Buyer photo lives on the private disk under buyer-photos/{id}/, as AccountPhotoController keeps it. */
    private function avatar(Buyer $buyer, string $handle): void
    {
        if ($buyer->profilePhotoPath() !== null && Storage::disk('local')->exists($buyer->profilePhotoPath())) {
            return;
        }

        $file = database_path('seeders/data/demo/images/avatars/'.$handle.'.png');
        if (! is_file($file)) {
            return;
        }

        $path = Storage::disk('local')->putFile('buyer-photos/'.$buyer->id, new File($file));
        $buyer->forceFill(['settings' => array_merge((array) $buyer->settings, ['profile_photo' => $path])])->saveQuietly();
    }
}

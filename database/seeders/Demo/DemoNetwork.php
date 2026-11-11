<?php

namespace Database\Seeders\Demo;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Category;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared definitions for the demo marketplace seeders: the PSGC snapshot, the
 * target areas, account naming and the one shared demo password.
 *
 * Handles are lowercase letters and digits only (no hyphens or dots), so a
 * demo login is easy to type: buyerlaguna01@zefanya.test.
 */
final class DemoNetwork
{
    /** One password for every demo account, the same one DeliveryScenarioSeeder already uses. */
    public const PASSWORD = DeliveryScenarioSeeder::PASSWORD;

    public const DOMAIN = 'zefanya.test';

    /** Short, typable codes used inside rider and courier handles. */
    public const CODES = ['laguna' => 'lag', 'bulacan' => 'bul', 'metro' => 'ncr', 'batangas' => 'bat', 'davao' => 'dav', 'cebu' => 'ceb'];

    /**
     * The sixth target area. The other five are DeliveryScenarioSeeder::PROVIDERS;
     * Cebu has no preexisting demo provider (it is the unsupported-address
     * scenario there), so this seeder family adds one.
     *
     * @var array<string, mixed>
     */
    public const CEBU_PROVIDER = [
        'name' => 'Demo Cebu Express',
        'hub' => 'Cebu Province Hub',
    ];

    /**
     * Where the seller, buyer and anchor accounts live: three municipalities
     * per area, named as PSGC names them. The first is the area's busiest
     * city; the others give the different-municipality scenarios.
     *
     * @var array<string, list<string>>
     */
    public const ANCHORS = [
        'laguna' => ['City of Calamba', 'City of Santa Rosa', 'City of San Pablo'],
        'bulacan' => ['City of Malolos', 'City of Meycauayan', 'Baliuag'],
        'metro' => ['Quezon City', 'City of Makati', 'City of Manila'],
        'batangas' => ['Batangas City', 'City of Lipa', 'City of Tanauan'],
        'davao' => ['City of Davao', 'City of Digos', 'Bansalan'],
        'cebu' => ['City of Cebu', 'City of Mandaue', 'City of Lapu-Lapu'],
    ];

    private static ?array $psgc = null;

    private static ?string $hash = null;

    /**
     * The PSGC snapshot: area key => province, region and every city or
     * municipality with all of its barangays, taken from the PSGC API.
     *
     * @return array<string, array{province: array{0: string, 1: string}, region: array{0: string, 1: string}, municipalities: list<array{code: string, name: string, barangays: list<array{0: string, 1: string}>}>}>
     */
    public static function psgc(): array
    {
        return self::$psgc ??= json_decode(
            (string) file_get_contents(database_path('seeders/data/demo/psgc.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Every target area, or only those named in the DEMO_SEED_AREAS process
     * variable (comma separated, e.g. "laguna"). It is read from the process
     * environment, never from .env, so a plain run still seeds all six.
     *
     * @return list<string>
     */
    public static function areaKeys(): array
    {
        $keys = array_keys(self::psgc());
        $only = array_filter(array_map('trim', explode(',', (string) getenv('DEMO_SEED_AREAS'))));

        return $only === [] ? $keys : array_values(array_intersect($keys, $only));
    }

    /** @return array{code: string, name: string, barangays: list<array{0: string, 1: string}>} */
    public static function municipality(string $area, string $code): array
    {
        foreach (self::psgc()[$area]['municipalities'] as $municipality) {
            if ($municipality['code'] === $code) {
                return $municipality;
            }
        }

        throw new RuntimeException("PSGC snapshot has no municipality {$code} in {$area}.");
    }

    /**
     * The anchor municipalities of an area, in ANCHORS order.
     *
     * @return list<array{code: string, name: string, barangays: list<array{0: string, 1: string}>}>
     */
    public static function anchors(string $area): array
    {
        $byName = [];
        foreach (self::psgc()[$area]['municipalities'] as $municipality) {
            $byName[$municipality['name']] = $municipality;
        }

        return array_map(
            fn (string $name): array => $byName[$name] ?? throw new RuntimeException("PSGC snapshot has no {$name} in {$area}."),
            self::ANCHORS[$area],
        );
    }

    /** Icons of the fixed category list created by the categories migration. */
    private const CATEGORY_ICONS = [
        'Health and Beauty' => 'heart-pulse', 'Home and Garden' => 'flower-2', "Men's Apparel" => 'shirt',
        "Women's Apparel" => 'sparkles', 'Electronics and Gadgets' => 'smartphone', 'Food and Gourmet' => 'utensils',
        'Jewelry and Watches' => 'gem', 'Office and School Supplies' => 'pencil-ruler',
    ];

    /** The category's id; a category the migration already created is reused, a missing one is added. */
    public static function categoryId(string $label): int
    {
        return (int) Category::query()
            ->firstOrCreate(['label' => $label], ['icon' => self::CATEGORY_ICONS[$label] ?? 'tag'])->id;
    }

    public static function email(string $handle): string
    {
        return $handle.'@'.self::DOMAIN;
    }

    /** Lowercase ASCII letters and digits only. */
    public static function slug(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text))) ?? '';
    }

    /** "City of Calamba" -> "Calamba"; "City of Davao" -> "Davao City". */
    public static function shortName(string $municipality): string
    {
        return str_starts_with($municipality, 'City of ') ? substr($municipality, 8).(str_contains($municipality, 'Davao') ? ' City' : '') : $municipality;
    }

    public static function municipalitySlug(string $municipality): string
    {
        return self::slug(self::shortName($municipality));
    }

    /** Hashed once so thousands of accounts do not each pay for a bcrypt round. */
    public static function passwordHash(): string
    {
        return self::$hash ??= Hash::make(self::PASSWORD);
    }

    /** The real company every Laguna demo seeder serves, when the database has it. */
    public const LAGUNA_PROVIDER_NAME = 'Ninja Express';

    /**
     * Process variable (never .env) that pins the Laguna company's id. When set it must
     * match an approved, unarchived Ninja Express in Laguna, or the seeders stop.
     */
    public const LAGUNA_PROVIDER_ID_ENV = 'DEMO_LAGUNA_PROVIDER_ID';

    /**
     * The provider account for an area, whether DeliveryScenarioSeeder or this family created it.
     * Laguna belongs to Ninja Express; Demo Laguna Express is only the fallback on a database without it.
     */
    public static function provider(string $area): ?LogisticsProvider
    {
        if ($area === 'laguna' && ($ninja = self::ninjaExpress()) !== null) {
            return $ninja;
        }

        $email = $area === 'cebu' ? self::email('logisticscebu') : DeliveryScenarioSeeder::email('logistics-'.$area);

        return LogisticsProvider::query()->where('email', $email)->first();
    }

    /**
     * Ninja Express, verified by more than its name: approved, not archived, based in
     * Laguna, and (when DEMO_LAGUNA_PROVIDER_ID is set) carrying exactly that id.
     * Null on a database that has no such company; an ambiguous match stops the run.
     */
    public static function ninjaExpress(): ?LogisticsProvider
    {
        $pinned = trim((string) getenv(self::LAGUNA_PROVIDER_ID_ENV));

        $matches = LogisticsProvider::query()
            ->where('business_name', self::LAGUNA_PROVIDER_NAME)
            ->where('province_name', 'Laguna')
            ->where('status', LogisticsProviderStatus::Approved->value)
            ->whereNull('archived_at')
            ->when($pinned !== '', fn ($query) => $query->whereKey((int) $pinned))
            ->orderBy('id')
            ->get();

        if ($pinned !== '' && $matches->isEmpty()) {
            throw new RuntimeException(self::LAGUNA_PROVIDER_ID_ENV."={$pinned} is not an approved, unarchived ".self::LAGUNA_PROVIDER_NAME.' based in Laguna.');
        }

        if ($matches->count() > 1) {
            throw new RuntimeException('More than one '.self::LAGUNA_PROVIDER_NAME.' exists in Laguna; set '.self::LAGUNA_PROVIDER_ID_ENV.' to the right id.');
        }

        return $matches->first();
    }

    /**
     * What a provider's demo Rider emails carry after the area code: nothing for the area's own
     * demo company, so its existing accounts keep their addresses, and p<id> for any other
     * company seeded into the area. Two companies therefore never share a demo identity.
     */
    public static function demoTag(LogisticsProvider $provider, string $area): string
    {
        $own = $area === 'cebu' ? self::email('logisticscebu') : DeliveryScenarioSeeder::email('logistics-'.$area);

        return $provider->email === $own ? '' : 'p'.$provider->id;
    }

    /**
     * Every target area in PSGC order, ignoring DEMO_SEED_AREAS, so a seeded
     * position (names, phone numbers) never shifts when a run is limited to one area.
     *
     * @return list<string>
     */
    public static function allAreaKeys(): array
    {
        return array_keys(self::psgc());
    }

    /** The platform's own Admin; a demo Admin never approves partnerships. */
    public static function admin(): ?Admin
    {
        return Admin::query()->where('email', 'not like', '%'.DeliveryScenarioSeeder::SLUG_PREFIX.'%')->orderBy('id')->first();
    }

    /** A deterministic fictional mobile number in the 0917 test block. */
    public static function phone(int $block, int $sequence): string
    {
        return sprintf('0917%d%06d', $block, $sequence);
    }

    public const FIRST_NAMES = [
        'Alon', 'Bayani', 'Carlito', 'Dindo', 'Edgardo', 'Felix', 'Gener', 'Harold', 'Ismael', 'Jun',
        'Kiko', 'Lando', 'Marlon', 'Nestor', 'Orly', 'Paolo', 'Quino', 'Ronnie', 'Silverio', 'Tomas',
        'Ana', 'Bea', 'Corazon', 'Dalisay', 'Elena', 'Fe', 'Gloria', 'Hazel', 'Imelda', 'Joy',
        'Kristine', 'Lourdes', 'Maricel', 'Nena', 'Olivia', 'Perla', 'Rosalie', 'Stella', 'Tessa', 'Violeta',
    ];

    public const LAST_NAMES = [
        'Abad', 'Bautista', 'Cabrera', 'Dimaculangan', 'Escudero', 'Fabian', 'Gatchalian', 'Hizon', 'Ilagan', 'Jacinto',
        'Katigbak', 'Landicho', 'Macaraeg', 'Natividad', 'Ocampo', 'Panganiban', 'Quizon', 'Resurreccion', 'Sarmiento', 'Tolentino',
        'Umali', 'Villanueva', 'Yambao', 'Zamora', 'Aguinaldo', 'Balagtas', 'Calderon', 'Dizon', 'Evangelista', 'Fajardo',
    ];

    /** A fictional full name picked from the pools by index. */
    public static function personName(int $seed): array
    {
        return [
            self::FIRST_NAMES[$seed % count(self::FIRST_NAMES)],
            self::LAST_NAMES[intdiv($seed, 7) % count(self::LAST_NAMES)],
        ];
    }
}

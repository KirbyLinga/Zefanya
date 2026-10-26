<?php

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\LogisticsHubService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Explicit isolated-server harness. It never loads a database target from .env.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});

function checkStage(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function stageConnection(string $schema): void
{
    checkStage((bool) preg_match('/^barangay_stage_[a-z0-9_]+$/', $schema), 'Unsafe staging schema');
    config(['database.default' => 'barangay_staging', 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
        'database.connections.barangay_staging' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13327,
            'database' => $schema, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]]);
    DB::purge('barangay_staging');
    $info = DB::selectOne('SELECT VERSION() AS version, @@port AS port, DATABASE() AS db');
    checkStage(str_starts_with($info->version, '8.0.46') && (int) $info->port === 13327 && $info->db === $schema, 'Wrong staging server');
    Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']]);
    Cache::put('psgc:barangays:030540100', [['code' => '0305401001', 'name' => 'First'], ['code' => '0305401002', 'name' => 'Second']]);
}
function stageParcel(string $code = '0305401001'): SellerOrder
{
    return SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
        'seller_id' => Seller::factory()->create(['barangay_code' => $code])->id,
        'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => $code]])->id,
    ]);
}
function stageFleet(LogisticsProvider $provider): array
{
    $parent = LogisticsHub::query()->create(['logistics_provider_id' => $provider->id, 'name' => 'Staging Province', 'hub_type' => LogisticsHub::PROVINCE,
        'province_name' => 'Pampanga', 'region_code' => '030000000', 'province_code' => '030540000', 'is_active' => true, 'approval_status' => 'approved']);
    $station = LogisticsHub::query()->create(['logistics_provider_id' => $provider->id, 'parent_hub_id' => $parent->id, 'name' => 'Staging Station', 'hub_type' => LogisticsHub::STATION,
        'province_name' => 'Pampanga', 'municipality' => 'San Fernando', 'region_code' => '030000000', 'province_code' => '030540000', 'is_active' => true, 'approval_status' => 'approved']);
    $area = (new LogisticsHubService)->saveCoverage($provider->id, ['name' => 'First', 'station_hub_id' => $station->id, 'city_municipality_code' => '030540100', 'barangay_code' => '0305401001']);
    $rider = Rider::factory()->create(['logistics_provider_id' => $provider->id, 'station_hub_id' => $station->id, 'status' => 'approved', 'email_verified_at' => now()]);
    $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id, 'rider_id' => $rider->id, 'area_id' => $area->id, 'is_available' => true]);

    return [$station, $area, $courier];
}

if (($argv[1] ?? '') === 'worker') {
    stageConnection($argv[2]);
    $mode = $argv[3];
    if ($mode === 'disable') {
        $area = Area::findOrFail((int) $argv[4]);
        (new LogisticsHubService)->saveCoverage($area->logistics_provider_id, $area->only(['name', 'station_hub_id', 'city_municipality_code', 'barangay_code']) + ['is_active' => false], $area);
        echo json_encode(['disabled' => true]);
        exit;
    }
    $deadline = microtime(true) + 15;
    while (! file_exists($argv[4])) {
        checkStage(microtime(true) < $deadline, 'Barrier timeout');
        usleep(1000);
    }
    if ($mode === 'claim') {
        try {
            DB::table('areas')->insert(['logistics_provider_id' => (int) $argv[5], 'station_hub_id' => (int) $argv[6], 'name' => 'Race', 'municipality' => 'San Fernando', 'barangay_code' => '0305401002', 'is_active' => true]);
            echo json_encode(['winner' => true]);
        } catch (UniqueConstraintViolationException) {
            echo json_encode(['winner' => false]);
        }
    } else {
        $courier = (new CourierAssignmentService)->reservePickup(SellerOrder::findOrFail((int) $argv[5]), preferredCourierId: (int) $argv[6]);
        echo json_encode(['winner' => $courier !== null]);
    }
    exit;
}

$server = new PDO('mysql:host=127.0.0.1;port=13327', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
checkStage(str_starts_with($server->getAttribute(PDO::ATTR_SERVER_VERSION), '8.0.46'), 'Wrong MySQL version');
$schema = 'barangay_stage_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
$server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
stageConnection($schema);
Artisan::call('migrate', ['--database' => 'barangay_staging', '--force' => true]);
checkStage(Schema::hasColumn('areas', 'active_barangay_code'), 'Coverage migration did not run');
checkStage(Schema::hasColumn('seller_orders', 'delivery_address_verification'), 'Evidence migration did not run');
$results = ['server' => 'MySQL 8.0.46 / isolated port 13327', 'schema' => $schema, 'fresh_migrations' => true];

function raceStage(string $schema, string $mode, array $arguments): array
{
    $barrier = storage_path('framework/testing/'.$schema.'_'.bin2hex(random_bytes(3)).'.go');
    $running = [];
    foreach ($arguments as $args) {
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker', $schema, $mode, $barrier], array_map('strval', $args)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[] = [$process, $pipes];
    }
    touch($barrier);
    $outcomes = [];
    foreach ($running as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        checkStage(proc_close($process) === 0, 'Worker failed: '.$error.' '.$output);
        $outcomes[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    return $outcomes;
}
$provider = LogisticsProvider::factory()->create(['status' => 'approved']);
[$station, $area, $courier] = stageFleet($provider);
$parcel = stageParcel();
$race = raceStage($schema, 'assign', [[$parcel->id, $courier->id], [$parcel->id, $courier->id]]);
checkStage(count(array_filter($race, fn ($r) => $r['winner'])) === 1, 'Same parcel race had wrong winner count');
checkStage($parcel->deliveryAssignments()->count() === 1, 'Duplicate history after race');
$results['same_parcel_assignment_race'] = $race;

$secondRider = Rider::factory()->create(['logistics_provider_id' => $provider->id, 'station_hub_id' => $station->id, 'status' => 'approved', 'email_verified_at' => now()]);
$secondCourier = Courier::factory()->create(['logistics_provider_id' => $provider->id, 'rider_id' => $secondRider->id, 'area_id' => $area->id, 'is_available' => true]);
$one = stageParcel();
$two = stageParcel();
$race = raceStage($schema, 'assign', [[$one->id, $secondCourier->id], [$two->id, $secondCourier->id]]);
checkStage(count(array_filter($race, fn ($r) => $r['winner'])) === 1, 'One rider was assigned to two parcels');
$results['same_rider_assignment_race'] = $race;
$race = raceStage($schema, 'claim', [[$provider->id, $station->id], [$provider->id, $station->id]]);
checkStage(count(array_filter($race, fn ($r) => $r['winner'])) === 1, 'Generated uniqueness failed under concurrency');
$results['generated_index_race'] = $race;

// Establish an old snapshot, commit a coverage removal on another connection,
// then exercise the provider-less path inside that original transaction.
$other = LogisticsProvider::factory()->create(['status' => 'approved']);
[$otherStation, $otherArea, $otherCourier] = stageFleet($other);
(new LogisticsHubService)->saveCoverage($other->id, ['name' => 'Second', 'station_hub_id' => $otherStation->id, 'city_municipality_code' => '030540100', 'barangay_code' => '0305401002']);
$otherCourier->update(['area_id' => null]);
$staleParcel = stageParcel();
DB::beginTransaction();
Area::query()->count();
$pipes = [];
$child = proc_open([PHP_BINARY, __FILE__, 'worker', $schema, 'disable', (string) $otherArea->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
checkStage(proc_close($child) === 0, 'Coverage removal worker failed: '.$error.' '.$output);
checkStage((json_decode($output, true, flags: JSON_THROW_ON_ERROR)['disabled'] ?? false) === true, 'Coverage removal did not succeed');
checkStage(Area::findOrFail($otherArea->id)->is_active, 'Old REPEATABLE READ snapshot was not established');
$selected = (new CourierAssignmentService)->reservePickup($staleParcel, preferredCourierId: $otherCourier->id);
checkStage($selected === null && $staleParcel->fresh()->courier_id === null, 'Assignment used stale coverage: '.json_encode(['selected' => $selected?->id, 'parcel_courier' => $staleParcel->fresh()->courier_id, 'area_current' => Area::whereKey($otherArea->id)->lockForUpdate()->first()?->is_active]));
DB::commit();
$results['repeatable_read_stale_coverage_rejected'] = true;

// Recovery fixture models the partial-commit boundary before unique-index DDL.
$recovery = $schema.'_recovery';
$server->exec('CREATE DATABASE `'.$recovery.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
stageConnection($recovery);
DB::statement('CREATE TABLE logistics_providers (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
DB::statement('CREATE TABLE areas (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logistics_provider_id BIGINT UNSIGNED NOT NULL, station_hub_id BIGINT UNSIGNED NULL, name VARCHAR(255) NOT NULL, municipality VARCHAR(255) NOT NULL, city_municipality_code VARCHAR(20) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, UNIQUE KEY areas_provider_city_unique (logistics_provider_id,city_municipality_code), CONSTRAINT stage_provider_fk FOREIGN KEY (logistics_provider_id) REFERENCES logistics_providers(id)) ENGINE=InnoDB');
DB::table('logistics_providers')->insert(['id' => 1]);
DB::statement('ALTER TABLE areas ADD barangay_code VARCHAR(20) NULL');
DB::table('areas')->insert([
    ['logistics_provider_id' => 1, 'name' => 'First retained', 'municipality' => 'City', 'city_municipality_code' => 'city-a', 'barangay_code' => 'duplicate'],
    ['logistics_provider_id' => 1, 'name' => 'Second retained', 'municipality' => 'City', 'city_municipality_code' => 'city-b', 'barangay_code' => 'duplicate'],
]);
$migration = require __DIR__.'/../../database/migrations/2026_10_21_083944_add_barangay_coverage_to_areas_table.php';
$failed = false;
try {
    $migration->up();
} catch (UniqueConstraintViolationException) {
    $failed = true;
}
checkStage($failed && Schema::hasColumn('areas', 'active_barangay_code'), 'Partial DDL failure was not exercised');
checkStage(collect(Schema::getIndexes('areas'))->contains('name', 'areas_provider_city_unique'), 'Old uniqueness was dropped before new protection existed');
DB::table('areas')->where('name', 'Second retained')->update(['is_active' => false]);
$migration->up();
$migration->up();
checkStage(DB::table('areas')->count() === 2, 'Recovery lost legacy rows');
$results['partial_ddl_failure_recovery_and_idempotence'] = true;
$results['ordinary_provider_fk_index'] = collect(Schema::getIndexes('areas'))->contains('name', 'areas_provider_coverage_index');
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

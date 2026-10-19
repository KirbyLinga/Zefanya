<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class RiderTestBase extends TestCase
{
    use LazilyRefreshDatabase;

    protected string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = getenv('RIDER_FIXTURE_DIR') ?: base_path('tests/fixtures/rider');
        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function logisticsA(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'business_name' => 'Bilis Hatid Express Inc. '.uniqid(),
            'email' => 'operations+'.uniqid().'@bilishatid.example.com',
            'contact_no' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);
    }

    protected function logisticsB(string $status = 'approved'): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'business_name' => 'Agila Cargo Movers Corp. '.uniqid(),
            'email' => 'admin+'.uniqid().'@agilacargo.example.com',
            'contact_no' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => $status,
            'email_verified_at' => now(),
            'approved_at' => $status === 'approved' ? now() : null,
        ]);
    }

    protected function seedBuyer(): Buyer
    {
        return Buyer::factory()->create([
            'email' => 'juanmiguel.delacruz@example.com',
            'contact_no' => '09171234567',
            'status' => 'approved',
        ]);
    }

    protected function seedSeller(): Seller
    {
        return Seller::factory()->create([
            'email' => 'mariaclara.santos@example.com',
            'status' => 'approved',
        ]);
    }

    protected function rfile(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rider').'_'.$name;
        copy($this->fixtureDir.'/'.$name, $tmp);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pedro Jose',
            'last_name' => 'Garcia',
            'birthday' => '1995-03-22',
            'email' => 'pedrojose.garcia@example.com',
            'contact_no' => '09451239876',
            'license_no' => 'N01-23-456789',
            'license_expiry' => '2030-03-22',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => '123ABC',
            'drivers_license' => $this->rfile('drivers_license.jpg'),
            'or_cr' => $this->rfile('or_cr.pdf'),
        ], $overrides);
    }

    protected function postRider(LogisticsProvider $p, array $overrides = []): TestResponse
    {
        return $this->actingAs($p, 'logistics')
            ->post(route('logistics.riders.store'), $this->validPayload($overrides));
    }

    protected function pendingRider(?LogisticsProvider $p = null): array
    {
        $p ??= $this->logisticsA();
        static $n = 0;
        $n++;
        $s = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $id = DB::table('riders')->insertGetId([
            'logistics_provider_id' => $p->id,
            'first_name' => 'Pedro Jose', 'last_name' => 'Garcia',
            'birthday' => '1995-03-22', 'email' => "pending{$s}@example.com",
            'contact_no' => '0945100'.$s, 'license_no' => "N01-23-45{$s}",
            'license_expiry' => '2030-03-22', 'vehicle_type' => 'Motorcycle',
            'plate_number' => "PND{$s}", 'status' => 'pending_activation',
            'drivers_license_path' => 'rider-licenses/test.jpg',
            'or_cr_path' => 'rider-or-crs/test.pdf',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = (array) DB::table('riders')->where('id', $id)->first();
        $row['id'] = $id;

        return $row;
    }

    protected function riderStatus(array $rider): string
    {
        return (string) DB::table('riders')->where('id', $rider['id'])->value('status');
    }

    protected function makeToken(array $rider, ?Carbon $issuedAt = null): string
    {
        $token = bin2hex(random_bytes(32));
        $issuedAt ??= Carbon::now('Asia/Manila');
        DB::table('rider_activation_tokens')->insert([
            'rider_id' => $rider['id'], 'token_hash' => hash('sha256', $token),
            'expires_at' => $issuedAt->copy()->addHours(24),
            'created_at' => $issuedAt, 'updated_at' => $issuedAt,
        ]);

        return $token;
    }
}

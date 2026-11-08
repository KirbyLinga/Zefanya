<?php

namespace Tests\Feature;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LinehaulProfile;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Services\Logistics\LinehaulProfileService;
use App\Services\Logistics\VehiclePlateService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class LinehaulVehicleTest extends TestCase
{
    use LazilyRefreshDatabase, BootstrapsCrossProviderHandoffs;

    private function fixture(): LinehaulDriver
    {
        $this->withoutVite();
        Storage::fake('local');
        Notification::fake();
        $network = $this->operationalHandoffProvider('Vehicle Carrier', '043400000', 'Laguna', '043405000', 'Calamba', '043405001', 'Bagong', '040000000', 'CALABARZON');
        $driver = new LinehaulDriver;
        $driver->forceFill(['name' => 'Vehicle Courier', 'email' => 'vehicle@example.com', 'password' => 'Courier@Test2026',
            'logistics_provider_id' => $network['provider']->id, 'home_hub_id' => $network['station']->id,
            'status' => 'active', 'email_verified_at' => now()])->save();

        return $driver;
    }

    private function payload(int $version = 0, bool $files = true): array
    {
        return ['version' => $version, 'plate_number' => 'NAB4521', 'license_no' => 'N02-15-345678',
            'license_expiry' => now('Asia/Manila')->addYear()->toDateString(), 'license_codes' => ['B', 'B2', 'C'],
            'vehicle_type' => '4-Wheeler Closed Van Truck', 'capacity_kg' => '3000',
            ...($files ? ['drivers_license' => UploadedFile::fake()->image('license.jpg'),
                'or_cr' => UploadedFile::fake()->createWithContent('or-cr.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
                'nbi_clearance' => UploadedFile::fake()->image('nbi.png')] : [])];
    }

    private function api(LinehaulDriver $driver): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($driver->createToken('vehicle', ['linehaul'])->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function staff(LogisticsProvider $provider): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($provider, 'logistics');
    }

    public function test_vehicle_routes_require_real_role_and_never_accept_privileged_fields(): void
    {
        $driver = $this->fixture();
        $this->getJson('/api/courier/vehicle')->assertUnauthorized();
        $this->postJson('/api/courier/vehicle', [])->assertUnauthorized();
        $this->getJson('/api/courier/vehicle/documents/or_cr')->assertUnauthorized();
        $buyer = \App\Models\Buyer\Buyer::factory()->create();
        $this->withToken($buyer->createToken('forged', ['linehaul'])->plainTextToken)->getJson('/api/courier/vehicle')->assertForbidden();
        $this->postJson('/api/courier/vehicle', [])->assertForbidden();
        $this->getJson('/api/courier/vehicle/documents/or_cr')->assertForbidden();
        $this->api($driver)->getJson('/api/courier/vehicle')->assertOk()->assertJsonPath('data.setup_required', false)->assertJsonPath('data.profile', null);
        foreach (['driver_id', 'status', 'review_status', 'reviewed_by', 'documents', 'logistics_provider_id', 'home_hub_id', 'on_duty', 'email_verified_at'] as $field) {
            $this->post('/api/courier/vehicle', [...$this->payload(), $field => 'forged'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson('/api/courier/vehicle?driver_id=99')->assertUnprocessable();
        $this->getJson('/api/courier/vehicle/documents/unknown')->assertNotFound();
        $this->assertSame(0, LinehaulProfile::count());
        $driver->forceFill(['status' => 'suspended'])->save();
        $this->api($driver)->getJson('/api/courier/vehicle')->assertForbidden();
    }

    public function test_submission_private_documents_company_review_and_replacement_require_reverification(): void
    {
        $driver = $this->fixture();
        $this->api($driver)->post('/api/courier/vehicle', [...$this->payload(), 'plate_number' => 'nab-4521'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.profile.plate_number', 'NAB4521')->assertJsonPath('data.profile.review_status', 'pending')
            ->assertJsonPath('data.profile.version', 1)->assertJsonMissingPath('data.profile.documents.or_cr.path');
        $profile = LinehaulProfile::sole();
        $old = $profile->documents['or_cr']['path'];
        Storage::disk('local')->assertExists($old);
        $this->get('/api/courier/vehicle/documents/or_cr')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $other = $driver->replicate();
        $other->forceFill(['email' => 'other-vehicle@example.com'])->save();
        $this->api($other)->get('/api/courier/vehicle/documents/or_cr')->assertNotFound();
        $this->staff($driver->provider)->get(route('logistics.couriers.vehicle', $driver))->assertOk()->assertSee('NAB4521')->assertSee('pending');
        $this->get(route('logistics.couriers.vehicle.document', [$driver, 'or_cr']))->assertOk();
        $this->post(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'All documents checked.'])->assertRedirect();
        $this->assertSame('approved', $profile->fresh()->review_status);
        $this->assertSame($driver->logistics_provider_id, $profile->fresh()->reviewed_by);
        $this->assertTrue(LinehaulProfileService::ready($driver));
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'rejected', 'note' => 'Replay must not overwrite the decision.'])->assertUnprocessable();
        $this->api($driver)->post('/api/courier/vehicle', [...$this->payload(1, false), 'capacity_kg' => 2500,
            'or_cr' => UploadedFile::fake()->image('updated.jpg')])->assertOk()->assertJsonPath('data.profile.capacity_kg', '2500.00')
            ->assertJsonPath('data.profile.review_status', 'pending')->assertJsonPath('data.profile.version', 2);
        $this->assertNotSame($old, $profile->fresh()->documents['or_cr']['path']);
        $this->assertNull($profile->fresh()->reviewed_by);
        $this->assertFalse(LinehaulProfileService::ready($driver));
        $this->assertFalse(\App\Services\Logistics\LinehaulDutyService::available($driver));
        $this->postJson('/api/courier/duty/on', ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10,
            'checklist' => array_fill_keys(\App\Services\Logistics\LinehaulDutyService::ITEMS, true)])->assertUnprocessable();
        $this->staff($driver->provider)->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 2,
            'decision' => 'approved', 'note' => 'Capacity corrected after inspection.', 'capacity_kg' => 3000])->assertRedirect();
        $this->assertSame('3000.00', $profile->fresh()->capacity_kg);
        $this->assertSame(3, $profile->fresh()->version);
        $this->assertSame('approved', $profile->fresh()->review_status);
    }

    public function test_expiry_capacity_and_document_boundaries_preserve_previous_records(): void
    {
        $driver = $this->fixture();
        $this->api($driver)->post('/api/courier/vehicle', [...$this->payload(), 'license_expiry' => now('Asia/Manila')->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('license_expiry');
        $this->post('/api/courier/vehicle', [...$this->payload(), 'license_expiry' => now('Asia/Manila')->toDateString(),
            'nbi_clearance' => UploadedFile::fake()->image('boundary.jpg')->size(5120)])->assertOk();
        $profile = LinehaulProfile::sole();
        $original = $profile->getAttributes();
        foreach ([
            ['nbi_clearance' => UploadedFile::fake()->image('too-large.jpg')->size(5223)],
            ['nbi_clearance' => UploadedFile::fake()->createWithContent('renamed_exe.jpg', 'MZ'.str_repeat('binary', 100))],
            ['nbi_clearance' => UploadedFile::fake()->create('price-list.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            ['capacity_kg' => 0], ['capacity_kg' => 50001], ['capacity_kg' => 'NaN'],
            ['license_codes' => ['A']], ['license_codes' => ['B', 'B']],
        ] as $bad) {
            $this->post('/api/courier/vehicle', [...$this->payload(1, false), ...$bad])->assertUnprocessable();
            $this->assertSame($original, $profile->fresh()->getAttributes());
        }
        $this->travel(1)->days();
        $this->staff($driver->provider)->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Checked.'])->assertUnprocessable();
        $this->assertSame('pending', $profile->fresh()->review_status);
    }

    public function test_off_duty_active_trip_and_version_rules_block_stale_or_working_edits(): void
    {
        $driver = $this->fixture();
        $this->api($driver);
        $driver->forceFill(['on_duty' => true])->save();
        $this->post('/api/courier/vehicle', $this->payload())->assertUnprocessable();
        $driver->forceFill(['on_duty' => false])->save();
        $this->api($driver)->post('/api/courier/vehicle', $this->payload())->assertOk();
        $this->post('/api/courier/vehicle', $this->payload())->assertUnprocessable();
        $this->staff($driver->provider)->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 9, 'decision' => 'approved', 'note' => 'Old page.'])->assertUnprocessable();
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => '   '])->assertUnprocessable();
        $manifest = app(\App\Services\Logistics\ManifestService::class)->create($driver->provider, $driver->home_hub_id,
            $driver->provider->hubs()->where('hub_type', 'province_hub')->firstOrFail()->id);
        (new \App\Models\Logistics\LinehaulAssignment)->forceFill(['manifest_id' => $manifest->id, 'linehaul_driver_id' => $driver->id])->save();
        $this->api($driver)->getJson('/api/courier/vehicle')->assertJsonPath('data.editable', false);
        $this->post('/api/courier/vehicle', $this->payload(1))->assertUnprocessable();
        $this->staff($driver->provider)->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Checked.'])->assertUnprocessable();
    }

    public function test_other_company_cannot_read_download_or_review_and_courier_cannot_self_approve(): void
    {
        $driver = $this->fixture();
        $this->api($driver)->post('/api/courier/vehicle', $this->payload())->assertOk();
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Forged.'])->assertUnauthorized();
        $other = LogisticsProvider::factory()->create(['status' => \App\Enums\LogisticsProviderStatus::Approved]);
        $this->staff($other)->getJson(route('logistics.couriers.vehicle', $driver))->assertForbidden();
        $this->get(route('logistics.couriers.index'))->assertOk()->assertDontSee($driver->name);
        $this->getJson(route('logistics.couriers.vehicle.document', [$driver, 'or_cr']))->assertForbidden();
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Forged.'])->assertForbidden();
        $driver->provider->forceFill(['archived_at' => now()])->save();
        $this->staff($driver->provider)->getJson(route('logistics.couriers.vehicle', $driver))->assertForbidden();
        $this->assertSame('pending', LinehaulProfile::sole()->review_status);
    }

    public function test_company_roster_is_private_and_rejected_records_can_be_corrected(): void
    {
        $driver = $this->fixture();
        $this->getJson(route('logistics.couriers.index'))->assertUnauthorized();
        $this->api($driver)->post('/api/courier/vehicle', $this->payload())->assertOk();
        $this->staff($driver->provider)->get(route('logistics.couriers.index'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee($driver->name)->assertSee('NAB4521');
        $this->getJson(route('logistics.couriers.index', ['logistics_provider_id' => 999]))->assertUnprocessable();
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Invalid capacity.', 'capacity_kg' => 0])->assertUnprocessable();
        $this->post(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'rejected', 'note' => 'Please replace the blurry OR/CR.'])->assertRedirect();
        $this->api($driver)->getJson('/api/courier/vehicle')->assertJsonPath('data.profile.review_status', 'rejected')
            ->assertJsonPath('data.profile.review_note', 'Please replace the blurry OR/CR.');
        $this->post('/api/courier/vehicle', [...$this->payload(1, false), 'or_cr' => UploadedFile::fake()->image('clear.jpg')])->assertOk()->assertJsonPath('data.profile.review_status', 'pending');
    }

    public function test_duplicate_plates_are_normalized_and_block_both_roles(): void
    {
        $driver = $this->fixture();
        Rider::factory()->create(['plate_number' => '123 ABC']);
        $this->api($driver)->post('/api/courier/vehicle', [...$this->payload(), 'plate_number' => '123abc'])->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->post('/api/courier/vehicle', $this->payload())->assertOk();
        $other = $driver->replicate();
        $other->forceFill(['email' => 'duplicate@example.com'])->save();
        $this->api($other)->post('/api/courier/vehicle', [...$this->payload(), 'plate_number' => 'nab-4521'])->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $input = Rider::factory()->raw(['logistics_provider_id' => $driver->logistics_provider_id, 'plate_number' => 'NAB4521']);
        $input = [...$input, 'password' => 'Rider@Test2026', 'drivers_license' => UploadedFile::fake()->image('license.jpg'), 'or_cr' => UploadedFile::fake()->image('orcr.jpg')];
        try {
            app(\App\Services\Rider\RiderRegistrationService::class)->register($input);
            $this->fail('Rider registration accepted a Courier plate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plate_number', $exception->errors());
        }
        $this->assertSame(1, Rider::count());
    }

    public function test_missing_file_or_failed_write_rolls_back_new_uploads_and_keeps_old_documents(): void
    {
        $driver = $this->fixture();
        $partial = $this->payload();
        unset($partial['nbi_clearance']);
        $this->api($driver)->post('/api/courier/vehicle', $partial)->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, LinehaulProfile::count());
        $this->post('/api/courier/vehicle', $this->payload())->assertOk();
        $profile = LinehaulProfile::sole();
        $paths = Storage::disk('local')->allFiles();
        LinehaulProfile::saving(function ($row): void {
            if ($row->version === 2) {
                throw new \RuntimeException('Simulated save failure');
            }
        });
        try {
            app(LinehaulProfileService::class)->submit($driver, $this->payload(1));
            $this->fail('Save failure was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated save failure', $exception->getMessage());
        } finally {
            LinehaulProfile::flushEventListeners();
        }
        $this->assertSame($paths, Storage::disk('local')->allFiles());
        $this->assertSame(1, $profile->fresh()->version);
        Storage::disk('local')->delete($profile->documents['or_cr']['path']);
        $this->staff($driver->provider)->postJson(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Checked.'])->assertUnprocessable();
    }

    public function test_vehicle_and_company_routes_are_throttled(): void
    {
        $driver = $this->fixture();
        $this->api($driver);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/courier/vehicle')->assertOk();
        }
        $this->post('/api/courier/vehicle', $this->payload())->assertStatus(429);
        $this->staff($driver->provider);
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('logistics.couriers.vehicle', $driver))->assertOk();
        }
        $this->postJson(route('logistics.couriers.vehicle.review', $driver), [])->assertStatus(429);

        $this->assertSame(0, LinehaulProfile::count());
    }

    public function test_approved_license_expiry_blocks_readiness_even_after_a_fresh_location(): void
    {
        $driver = $this->fixture();
        $this->api($driver)->post('/api/courier/vehicle', [...$this->payload(), 'license_expiry' => now('Asia/Manila')->toDateString()])->assertOk();
        $this->staff($driver->provider)->post(route('logistics.couriers.vehicle.review', $driver), ['version' => 1, 'decision' => 'approved', 'note' => 'Checked today.'])->assertRedirect();
        $point = ['latitude' => 14.2, 'longitude' => 121.3, 'accuracy' => 10];
        $this->api($driver)->postJson('/api/courier/duty/on', [...$point,
            'checklist' => array_fill_keys(\App\Services\Logistics\LinehaulDutyService::ITEMS, true)])->assertOk()->assertJsonPath('data.available', true);
        $this->travel(1)->days();
        $this->postJson('/api/courier/duty/heartbeat', $point)->assertOk()->assertJsonPath('data.available', false);
        $this->assertFalse(LinehaulProfileService::ready($driver));
        $this->assertSame('approved', LinehaulProfile::sole()->review_status);
    }
}

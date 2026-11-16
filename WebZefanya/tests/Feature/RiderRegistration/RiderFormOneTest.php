<?php

namespace Tests\Feature\RiderRegistration;

class RiderFormOneTest extends RiderTestBase
{
    public function test_tc_rid_001_create_rider_all_valid(): void
    {
        $provider = $this->logisticsA();
        $r = $this->postRider($provider);
        $r->assertRedirect();
        $r->assertSessionHasNoErrors();
        $this->assertDatabaseHas('riders', ['email' => 'pedrojose.garcia@example.com', 'status' => 'pending_activation', 'logistics_provider_id' => $provider->id]);
    }

    public function test_tc_rid_002_empty_form(): void
    {
        $p = $this->logisticsA();
        $this->actingAs($p, 'logistics')->post(route('logistics.riders.store'), [])
            ->assertSessionHasErrors(['first_name', 'last_name', 'birthday', 'email', 'contact_no', 'license_no', 'license_expiry', 'vehicle_type', 'plate_number', 'drivers_license', 'or_cr']);
    }

    public function test_tc_rid_003_one_blank_at_a_time(): void
    {
        $p = $this->logisticsA();
        $fields = ['first_name', 'last_name', 'birthday', 'email', 'contact_no', 'license_no', 'license_expiry', 'vehicle_type', 'plate_number'];
        foreach ($fields as $f) {
            $payload = $this->validPayload([$f => '']);
            unset($payload['drivers_license'], $payload['or_cr']);
            $this->actingAs($p, 'logistics')->post(route('logistics.riders.store'), $payload)->assertSessionHasErrors([$f]);
        }
    }

    public function test_tc_rid_004_names_with_numbers_symbols_rejected(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p, ['first_name' => 'Pedro Jose99', 'last_name' => 'Dela Cruz@#', 'email' => 'rider.test01@example.com', 'contact_no' => '09170000001', 'license_no' => 'N01-23-456789', 'plate_number' => 'TST0001'])->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_tc_rid_005_name_length_limits(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p, ['first_name' => 'P', 'email' => 'rider.test011@example.com', 'contact_no' => '09170000001', 'license_no' => 'N01-23-456781', 'plate_number' => 'TST001'])->assertSessionHasErrors(['first_name']);
        $this->postRider($p, ['first_name' => 'Po', 'email' => 'rider.test012@example.com', 'contact_no' => '09170000002', 'license_no' => 'N01-23-456782', 'plate_number' => 'TST002'])->assertSessionDoesntHaveErrors();
        $this->postRider($p, ['first_name' => 'Maria Cristina Angelica Bernadette Josefina Leonor', 'email' => 'rider.test013@example.com', 'contact_no' => '09170000003', 'license_no' => 'N01-23-456783', 'plate_number' => 'TST003'])->assertSessionDoesntHaveErrors();
        $this->postRider($p, ['first_name' => 'Maria Cristina Angelica Bernadette Josefina Leonora', 'email' => 'rider.test014@example.com', 'contact_no' => '09170000004', 'license_no' => 'N01-23-456784', 'plate_number' => 'TST004'])->assertSessionHasErrors(['first_name']);
    }
}

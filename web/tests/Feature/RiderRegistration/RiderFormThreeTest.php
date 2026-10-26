<?php

namespace Tests\Feature\RiderRegistration;

use Carbon\Carbon;

class RiderFormThreeTest extends RiderTestBase
{
    public function test_tc_rid_012_invalid_license_format_rejected(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        foreach (['N01-23-45678', 'N01-23-4567890', '101-23-456789', 'N0123456789X'] as $lic) {
            $i++;
            $this->postRider($p, ['license_no' => $lic, 'email' => "rider.test06{$i}@example.com", 'contact_no' => '0917000030'.$i, 'plate_number' => 'LIC00'.$i])->assertSessionHasErrors(['license_no']);
        }
    }

    public function test_tc_rid_013_duplicate_license_blocked(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p)->assertSessionDoesntHaveErrors();
        $this->postRider($p, ['email' => 'rider.test0701@example.com', 'contact_no' => '09170000011', 'plate_number' => 'LIC1301'])->assertSessionHasErrors(['license_no']);
    }

    public function test_tc_rid_014_license_expiry_boundary_manila(): void
    {
        $p = $this->logisticsA();
        $today = Carbon::now('Asia/Manila');
        $cases = [[$today->copy()->subDay()->format('Y-m-d'), false], [$today->format('Y-m-d'), true], [$today->copy()->addDay()->format('Y-m-d'), true]];
        $i = 0;
        foreach ($cases as [$exp, $ok]) {
            $i++;
            $r = $this->postRider($p, ['license_expiry' => $exp, 'email' => "rider.test08{$i}@example.com", 'contact_no' => '0917000040'.$i, 'license_no' => 'N01-23-46578'.$i, 'plate_number' => 'EXP00'.$i]);
            $ok ? $r->assertSessionDoesntHaveErrors() : $r->assertSessionHasErrors(['license_expiry']);
        }
    }

    public function test_tc_rid_015_duplicate_plate_blocked(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p)->assertSessionDoesntHaveErrors();
        $this->postRider($p, ['email' => 'rider.test0901@example.com', 'contact_no' => '09170000012', 'license_no' => 'D05-19-123456'])->assertSessionHasErrors(['plate_number']);
    }

    public function test_tc_rid_016_accepted_file_types(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        foreach (['drivers_license.jpg', 'drivers_license.png', 'drivers_license.pdf'] as $f) {
            $i++;
            $this->postRider($p, ['drivers_license' => $this->rfile($f), 'or_cr' => $this->rfile('or_cr.pdf'), 'email' => "rider.test10{$i}@example.com", 'contact_no' => '0917000050'.$i, 'license_no' => 'N01-23-46678'.$i, 'plate_number' => 'ACC00'.$i])->assertSessionDoesntHaveErrors();
        }
    }
}

<?php

namespace Tests\Feature\RiderRegistration;

use Carbon\Carbon;

class RiderFormTwoTest extends RiderTestBase
{
    public function test_tc_rid_006_age_at_18_limit_manila(): void
    {
        $p = $this->logisticsA();
        $today = Carbon::now('Asia/Manila');
        $cases = [[$today->copy()->subYears(18)->addDay()->format('Y-m-d'), false], [$today->copy()->subYears(18)->format('Y-m-d'), true], [$today->copy()->subYears(18)->subDay()->format('Y-m-d'), true]];
        $i = 0;
        foreach ($cases as [$dob, $ok]) {
            $i++;
            $r = $this->postRider($p, ['birthday' => $dob, 'email' => "rider.test02{$i}@example.com", 'contact_no' => '0917000010'.$i, 'license_no' => 'N01-23-45778'.$i, 'plate_number' => 'AG180'.$i]);
            $ok ? $r->assertSessionDoesntHaveErrors() : $r->assertSessionHasErrors(['birthday']);
        }
    }

    public function test_tc_rid_007_invalid_emails_rejected(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        $emails = ['pedrojose.garciaexample.com', 'pedrojose.garcia@', '@example.com', 'pedrojose.garcia@example', 'pedrojose garcia@example.com'];
        foreach ($emails as $email) {
            $i++;
            $this->postRider($p, ['email' => $email, 'contact_no' => '0917000020'.$i, 'license_no' => 'N01-23-45878'.$i, 'plate_number' => 'EML00'.$i])->assertSessionHasErrors(['email']);
        }
    }

    public function test_tc_rid_008_email_used_by_buyer_blocked(): void
    {
        $this->seedBuyer();
        $this->postRider($this->logisticsA(), ['email' => 'juanmiguel.delacruz@example.com'])->assertSessionHasErrors(['email']);
    }

    public function test_tc_rid_009_invalid_mobiles_rejected(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        foreach (['0917ABC4567', '0917-123-456', '12345678901', '02-8123-4567'] as $m) {
            $i++;
            $this->postRider($p, ['contact_no' => $m, 'email' => "rider.test03{$i}@example.com", 'license_no' => 'N01-23-45978'.$i, 'plate_number' => 'MOB00'.$i])->assertSessionHasErrors(['contact_no']);
        }
    }

    public function test_tc_rid_010_mobile_length_limits(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p, ['contact_no' => '0917123456', 'email' => 'rider.test0401@example.com', 'license_no' => 'N01-23-460789', 'plate_number' => 'MOB0101'])->assertSessionHasErrors(['contact_no']);
        $this->postRider($p, ['contact_no' => '09170000001', 'email' => 'rider.test0402@example.com', 'license_no' => 'N01-23-461789', 'plate_number' => 'MOB0102'])->assertSessionDoesntHaveErrors();
        $this->postRider($p, ['contact_no' => '091712345678', 'email' => 'rider.test0403@example.com', 'license_no' => 'N01-23-462789', 'plate_number' => 'MOB0103'])->assertSessionHasErrors(['contact_no']);
    }

    public function test_tc_rid_011_mobile_registered_and_plus63_equivalence(): void
    {
        $this->seedBuyer();
        $p = $this->logisticsA();
        $this->postRider($p, ['contact_no' => '09171234567', 'email' => 'rider.test0501@example.com', 'license_no' => 'N01-23-463789', 'plate_number' => 'MOB1101'])->assertSessionHasErrors(['contact_no']);
        $this->postRider($p, ['contact_no' => '+639171234567', 'email' => 'rider.test0502@example.com', 'license_no' => 'N01-23-464789', 'plate_number' => 'MOB1102'])->assertSessionHasErrors(['contact_no']);
    }
}

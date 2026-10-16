<?php

namespace Tests\Feature\RiderRegistration;

use Illuminate\Support\Facades\DB;

class RiderUploadSubmitTest extends RiderTestBase
{
    public function test_tc_rid_017_rejected_file_types(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        foreach (['price_list.docx', 'store_banner.gif', 'installer.exe'] as $f) {
            $i++;
            $this->postRider($p, ['drivers_license' => $this->rfile($f), 'email' => "rider.test11{$i}@example.com", 'contact_no' => '0917000060'.$i, 'license_no' => 'N01-23-46778'.$i, 'plate_number' => 'REJ00'.$i])->assertSessionHasErrors(['drivers_license']);
        }
    }

    public function test_tc_rid_018_file_size_boundary(): void
    {
        $p = $this->logisticsA();
        $i = 0;
        $cases = [['size_4.9MB.jpg', true], ['size_5.0MB.jpg', true], ['size_5.1MB.jpg', false]];
        foreach ($cases as [$f, $ok]) {
            $i++;
            $r = $this->postRider($p, ['drivers_license' => $this->rfile($f), 'email' => "rider.test12{$i}@example.com", 'contact_no' => '0917000070'.$i, 'license_no' => 'N01-23-46878'.$i, 'plate_number' => 'SIZ00'.$i]);
            $ok ? $r->assertSessionDoesntHaveErrors() : $r->assertSessionHasErrors(['drivers_license']);
        }
    }

    public function test_tc_rid_019_rapid_triple_save_creates_one(): void
    {
        $p = $this->logisticsA();
        $payload = $this->validPayload(['email' => 'markanthony.bautista@example.com', 'contact_no' => '09662345678', 'license_no' => 'D05-19-123456', 'plate_number' => 'NBC1234', 'first_name' => 'Mark Anthony', 'last_name' => 'Bautista', 'birthday' => '1999-08-09', 'license_expiry' => '2031-08-09', 'vehicle_type' => 'Van']);
        for ($k = 0; $k < 3; $k++) {
            $this->actingAs($p, 'logistics')->post(route('logistics.riders.store'), $payload);
        }
        $this->assertEquals(1, DB::table('riders')->where('email', 'markanthony.bautista@example.com')->count());
    }

    public function test_tc_rid_020_activate_with_valid_password(): void
    {
        $rider = $this->pendingRider();
        $token = $this->makeToken($rider);
        $this->post(route('rider.activate', $token), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertRedirect(route('login'));
        $this->assertSame('active', $this->riderStatus($rider));
    }
}

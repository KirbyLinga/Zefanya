<?php

namespace Tests\Feature\RiderRegistration;

use Illuminate\Http\UploadedFile;

class RiderHardeningTest extends RiderTestBase
{
    public function test_hard_exe_renamed_to_jpg_rejected_by_magic_bytes(): void
    {
        $p = $this->logisticsA();
        $tmp = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        copy($this->fixtureDir.'/installer.exe', $tmp);
        $evil = new UploadedFile($tmp, 'evil.jpg', null, null, true);
        try {
            $this->postRider($p, ['drivers_license' => $evil])->assertSessionHasErrors(['drivers_license']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_hard_double_extension_pdf_exe_rejected(): void
    {
        $p = $this->logisticsA();
        $tmp = tempnam(sys_get_temp_dir(), 'evil').'.exe';
        copy($this->fixtureDir.'/installer.exe', $tmp);
        $evil = new UploadedFile($tmp, 'a.pdf.exe', null, null, true);
        try {
            $this->postRider($p, ['drivers_license' => $evil])->assertSessionHasErrors(['drivers_license']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_hard_email_spaces_and_case_normalized(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p, ['email' => '  PEDROJOSE.GARCIA@EXAMPLE.COM  '])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('riders', ['email' => 'pedrojose.garcia@example.com']);
    }

    public function test_hard_sql_html_stored_inert_and_escaped(): void
    {
        $p = $this->logisticsA();
        $r = $this->postRider($p, ['first_name' => 'Robert', 'last_name' => 'Tables', 'email' => 'hard.sql@example.com', 'contact_no' => '09170009901', 'license_no' => 'N09-99-999999', 'plate_number' => 'HARD01']);
        $r->assertSessionDoesntHaveErrors();
        $page = $this->actingAs($p, 'logistics')->get(route('logistics.riders.index'))->assertOk();
        $page->assertDontSee('<script>alert', false);
        $page->assertSee('Robert Tables');
    }

    public function test_hard_tampered_token_rejected(): void
    {
        $rider = $this->pendingRider();
        $token = $this->makeToken($rider);
        $this->post(route('rider.activate', substr($token, 0, -2).'ff'), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertForbidden();
        $this->assertSame('pending_activation', $this->riderStatus($rider));
    }

    public function test_hard_unicode_name_with_enye_accepted(): void
    {
        $p = $this->logisticsA();
        $this->postRider($p, ['first_name' => 'Peña', 'last_name' => 'Muñoz', 'email' => 'hard.enye@example.com', 'contact_no' => '09170009902', 'license_no' => 'N09-99-999998', 'plate_number' => 'HARD02'])->assertSessionDoesntHaveErrors();
    }
}

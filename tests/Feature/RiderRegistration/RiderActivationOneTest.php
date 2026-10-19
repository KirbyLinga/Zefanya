<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Rider;
use App\Notifications\Rider\RiderActivation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RiderActivationOneTest extends RiderTestBase
{
    public function test_tc_rid_021_password_rule_violations(): void
    {
        foreach (['Ride@26', 'rider@2026', 'RIDER@2026', 'Rider@abcd', 'Rider2026'] as $pw) {
            $rider = $this->pendingRider();
            $token = $this->makeToken($rider);
            $this->post(route('rider.activate', $token), ['password' => $pw, 'password_confirmation' => $pw])->assertSessionHasErrors(['password']);
            $this->assertSame('pending_activation', $this->riderStatus($rider));
        }
    }

    public function test_tc_rid_022_confirm_mismatch(): void
    {
        $rider = $this->pendingRider();
        $this->post(route('rider.activate', $this->makeToken($rider)), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2027'])->assertSessionHasErrors(['password']);
    }

    public function test_tc_rid_023_expired_link(): void
    {
        $rider = $this->pendingRider();
        $token = $this->makeToken($rider, Carbon::now('Asia/Manila')->subMinutes(24 * 60 + 1));
        $this->post(route('rider.activate', $token), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertForbidden();
        $this->assertSame('pending_activation', $this->riderStatus($rider));
    }

    public function test_tc_rid_024_resend_invalidates_first_link(): void
    {
        $p = $this->logisticsA();
        $rider = $this->pendingRider($p);
        $first = $this->makeToken($rider);
        $this->actingAs($p, 'logistics')->post(route('logistics.riders.resend', $rider['id']))->assertRedirect();
        $second = DB::table('rider_activation_tokens')->where('rider_id', $rider['id'])->latest('id')->value('id');
        // Raw second token is only in the mailed notification; fetch via Notification fake instead:
        $secondRaw = null;
        Notification::assertSentTo(
            Rider::find($rider['id']),
            RiderActivation::class,
            function ($n) use (&$secondRaw): bool {
                $secondRaw = basename(parse_url($n->url, PHP_URL_PATH));

                return true;
            }
        );
        $this->post(route('rider.activate', $first), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertForbidden();
        $this->post(route('rider.activate', $secondRaw), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertRedirect();
    }

    public function test_tc_rid_025_reuse_used_link(): void
    {
        $rider = $this->pendingRider();
        $token = $this->makeToken($rider);
        $this->post(route('rider.activate', $token), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertRedirect();
        $this->post(route('rider.activate', $token), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026'])->assertForbidden();
    }
}

<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Rider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RiderAccessTest extends RiderTestBase
{
    public function test_tc_rid_026_login_before_activating_denied(): void
    {
        $rider = $this->pendingRider();
        $this->post(route('unified.login'), ['email' => $rider['email'], 'password' => 'Rider@2026'])->assertSessionHasErrors(['email']);
        $this->assertGuest('rider');
    }

    public function test_tc_rid_027_login_after_activating_dashboard(): void
    {
        $rider = $this->pendingRider();
        $this->post(route('rider.activate', $this->makeToken($rider)), ['password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026']);
        $this->post(route('unified.login'), ['email' => $rider['email'], 'password' => 'Rider@2026'])->assertRedirect();
        $this->assertSame('active', $this->riderStatus($rider));
    }

    public function test_tc_rid_028_company_isolation(): void
    {
        $a = $this->logisticsA();
        $b = $this->logisticsB();
        $rider = $this->pendingRider($a);
        $this->actingAs($a, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertSee($rider['email']);
        $this->actingAs($b, 'logistics')->get(route('logistics.riders.index'))->assertOk()->assertDontSee($rider['email']);
        $this->actingAs($b, 'logistics')->get(route('logistics.riders.show', $rider['id']))->assertForbidden();
    }

    public function test_tc_rid_029_public_has_no_rider_option_and_create_needs_login(): void
    {
        $this->get(route('register.type'))->assertOk()->assertDontSee('REGISTER AS RIDER', false);
        $this->get(route('logistics.riders.create'))->assertRedirect(route('login'));
    }

    public function test_tc_rid_030_buyer_seller_denied_even_direct_post(): void
    {
        $buyer = $this->seedBuyer();
        $seller = $this->seedSeller();
        // Cross-guard: buyer/seller session hits auth:logistics → redirect to
        // login (spec allows 403 or redirect). Must never succeed (200).
        $this->actingAs($buyer, 'buyer')->get(route('logistics.riders.create'))->assertRedirect(route('login'));
        $this->actingAs($seller, 'seller')->get(route('logistics.riders.create'))->assertRedirect(route('login'));
        $this->actingAs($buyer, 'buyer')->post(route('logistics.riders.store'), $this->validPayload())->assertRedirect(route('login'));
        $this->actingAs($seller, 'seller')->post(route('logistics.riders.store'), $this->validPayload())->assertRedirect(route('login'));
        $this->assertDatabaseMissing('riders', ['email' => 'pedrojose.garcia@example.com']);
    }

    public function test_tc_rid_031_pending_logistics_denied_even_direct_post(): void
    {
        $pending = $this->logisticsB('pending_approval');
        $this->actingAs($pending, 'logistics')->get(route('logistics.riders.create'))->assertRedirect();
        $this->actingAs($pending, 'logistics')->post(route('logistics.riders.store'), $this->validPayload())->assertRedirect();
        $this->assertDatabaseMissing('riders', ['email' => 'pedrojose.garcia@example.com']);
    }

    public function test_tc_rid_032_rider_cannot_open_other_panels(): void
    {
        $row = $this->pendingRider();
        DB::table('riders')->where('id', $row['id'])->update(['status' => 'active', 'password' => Hash::make('Rider@2026')]);
        $rider = Rider::where('email', $row['email'])->firstOrFail();
        $as = $this->actingAs($rider, 'rider');
        // Rider guard session on other panels → login redirect (spec: 403 or redirect).
        $as->get(route('logistics.dashboard'))->assertRedirect(route('login'));
        $as->get(route('seller.dashboard'))->assertRedirect(route('seller.login'));
        $as->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }
}

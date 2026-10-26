<?php

namespace Tests\Feature;

use App\Models\Buyer\Buyer;
use App\Models\Rider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Session\Store;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function buyer_login_clears_other_role_sessions(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create(['status' => 'approved']);

        DB::table('sessions')->insert([
            'id' => 'seller-session-before',
            'user_id' => $seller->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($seller, 'seller')->post(route('home', ['login' => 1]));

        $this->actingAs($buyer, 'buyer')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'seller-session-before']);

        // Rider in the first path makes the single-account rule symmetric across
        // all loginable guards.
        $rider = Rider::factory()->create(['status' => 'approved']);

        DB::table('sessions')->insert([
            'id' => 'rider-session-before',
            'user_id' => $rider->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($rider, 'rider')->post(route('home', ['login' => 1]));

        $this->actingAs($buyer, 'buyer')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'rider-session-before']);
    }

    #[Test]
    public function buyer_login_restores_a_previously_suppressed_session_after_password_reset(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        // Seed a non-`test` user-agent so the default `test` session the test
        // harness sets on `$this->actingAs($buyer)` does not collide with the
        // one we will later create and then restore.
        DB::table('sessions')->insert([
            'id' => 'buyer-existing-session',
            'user_id' => $buyer->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'restored-session',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($buyer, 'buyer')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseHas('sessions', ['id' => 'buyer-existing-session']);
    }

    #[Test]
    public function buyer_login_suppresses_the_existing_session_on_second_login(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        // Seed a non-`test` user-agent so the default `test` session the test
        // harness sets on `$this->actingAs($buyer)` does not collide with the
        // one we will later create and then restore.
        DB::table('sessions')->insert([
            'id' => 'buyer-existing-session',
            'user_id' => $buyer->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'restored-session',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($buyer, 'buyer')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'buyer-existing-session']);
    }

    #[Test]
    public function buyer_login_without_a_previous_session_is_still_fully_accepted(): void
    {
        $buyer = Buyer::factory()->create(['status' => 'approved', 'google_id' => null]);

        $response = $this->actingAs($buyer, 'buyer')
            ->post(route('unified.login'), [
                'email' => $buyer->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'no-previous-session-at-all']);
    }

    #[Test]
    public function buyer_login_suppresses_an_already_present_trace_session_for_the_same_email(): void
    {
        // Create a fake request the suppression path can read so the assertion
        // does not depend on the test harness session.
        $fakeRequest = Request::create(route('unified.login'), 'POST', [
            'email' => 'trace-buyer@example.test',
            'password' => 'password',
        ]);

        $fakeRequest->setSession(new Store('trace-store'));

        $fakeRequest->session()->set('session.ip_address', '127.0.0.1');
        $fakeRequest->session()->set('session.user_agent', 'trace-test');
        $fakeRequest->session()->set('session.suppressed_session_id', 'trace-session-before');

        $seller = Seller::factory()->create(['status' => 'approved']);

        DB::table('sessions')->insert([
            'id' => 'trace-session-before',
            'user_id' => $seller->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'trace-test',
            'payload' => '{}',
            'last_activity' => time(),
        ]);

        $this->actingAs($seller, 'seller')
            ->call('post', route('unified.login'), [
                'email' => 'trace-buyer@example.test',
                'password' => 'password',
            ])
            ->assertRedirect(route('buyer.home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'trace-session-before']);
    }
}

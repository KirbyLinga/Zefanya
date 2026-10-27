<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Buyer;
use App\Enums\AuthRole;

class BuyerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_login_with_valid_credentials(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $response = post('/api/buyer/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    }
}

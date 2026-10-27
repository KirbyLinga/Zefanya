<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Buyer;
use App\Models\Rider;
use App\Enums\AuthRole;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

beforeEach(function () {
    $this->withoutExceptionHandling();
});

describe('Buyer API Authentication', function () {

    it('can login with valid credentials', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $response = post('/api/buyer/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails with wrong password', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $response = post('/api/buyer/login', [
            'email' => $buyer->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails for pending buyer', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'pending',
            'email_verified_at' => now(),
        ]);

        $response = post('/api/buyer/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertUnauthorized()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails for suspended buyer', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'suspended',
            'email_verified_at' => now(),
        ]);

        $response = post('/api/buyer/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('buyer token cannot access rider endpoint (role restriction)', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $token = $buyer->createToken('api-token', ['buyer'])->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('buyer can access own profile endpoint', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $token = $buyer->createToken('api-token', ['buyer'])->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/buyer/profile');

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('logout revokes the token', function () {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $token = $buyer->createToken('api-token', ['buyer'])->plainTextToken;

        // Verify token is valid before logout
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/buyer/profile');

        // Log out
        post('/api/buyer/logout', []);

        // Token should now be revoked
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/buyer/profile')->assertForbidden();
    });

    });
});

describe('Rider API Authentication', function () {

    it('can login with valid credentials', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $response = post('/api/rider/login', [
            'email' => $rider->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails with wrong password', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $response = post('/api/rider/login', [
            'email' => $rider->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails for pending rider', function () {
        $rider = Rider::factory()->create([
            'status' => 'pending',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $response = post('/api/rider/login', [
            'email' => $rider->email,
            'password' => 'password',
        ]);

        $response->assertUnauthorized()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('fails for suspended rider', function () {
        $rider = Rider::factory()->create([
            'status' => 'suspended',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $response = post('/api/rider/login', [
            'email' => $rider->email,
            'password' => 'password',
        ]);

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('rider token cannot access buyer endpoint (role restriction)', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $token = $rider->createToken('api-token', ['rider'])->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/buyer/profile');

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('rider can access own profile endpoint', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $token = $rider->createToken('api-token', ['rider'])->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });

    it('logout revokes the rider token', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        $token = $rider->createToken('api-token', ['rider'])->plainTextToken;

        // Verify token is valid before logout
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        // Log out
        post('/api/rider/logout', []);

        // Token should now be revoked
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile')->assertForbidden();
    });

    it('expired rider token is rejected', function () {
        $rider = Rider::factory()->create([
            'status' => 'approved',
            'is_operational' => true,
            'email_verified_at' => now(),
        ]);

        // Create token with past expiration
        $token = $rider->createToken('api-token', ['rider'], now()->addMinutes(-10))->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });
});
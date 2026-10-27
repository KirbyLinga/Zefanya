<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Rider;
use App\Enums\AuthRole;

use function Pest\Laravel\post;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

beforeEach(function () {
    $this->withoutExceptionHandling();
});

describe('Rider API Authentication', function () {
    use RefreshDatabase;

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

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        post('/api/rider/logout', []);

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

        $token = $rider->createToken('api-token', ['rider'], now()->addMinutes(-10))->plainTextToken;

        $response = withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('/api/rider/profile');

        $response->assertForbidden()->assertJsonStructure(['success', 'message', 'data', 'errors']);
    });
});
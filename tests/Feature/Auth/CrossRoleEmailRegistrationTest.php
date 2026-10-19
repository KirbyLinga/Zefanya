<?php

namespace Tests\Feature\Auth;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrossRoleEmailRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_registration_rejects_an_email_already_used_by_a_seller(): void
    {
        Storage::fake('local');

        Seller::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson(route('register.buyer.store'), [
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'taken@example.com',
            'password' => 'Mamimili@2026',
            'password_confirmation' => 'Mamimili@2026',
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St.',
            'upload_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'terms' => '1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
        $this->assertDatabaseMissing('buyers', ['email' => 'taken@example.com']);
    }
}

<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageTest extends TestCase
{
    public function test_flutter_web_can_fetch_public_images_cross_origin(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/15/photo.jpg', 'product image');

        $this->get('/storage/products/15/photo.jpg', ['Origin' => 'http://localhost:5000'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertStreamedContent('product image');
    }

    public function test_private_files_do_not_gain_public_image_cors_headers(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/id.jpg', 'private document');

        $this->get('/private-storage/documents/id.jpg', ['Origin' => 'http://localhost:5000'])
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_guests_can_load_product_images_without_a_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/15/photo.jpg', 'product image');

        $this->get('/storage/products/15/photo.jpg')
            ->assertOk()
            ->assertStreamedContent('product image');
    }

    public function test_public_image_urls_do_not_depend_on_the_configured_app_host(): void
    {
        $this->assertSame('/storage', config('filesystems.disks.public.url'));
        $this->assertSame('/storage/products/15/photo.jpg', Storage::disk('public')->url('products/15/photo.jpg'));
    }

    public function test_missing_public_images_return_not_found(): void
    {
        Storage::fake('public');

        $this->get('/storage/products/15/missing.jpg')->assertNotFound();
    }

    public function test_private_documents_are_not_exposed_by_public_image_routes(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('documents/id.jpg', 'private document');

        $this->get('/storage/documents/id.jpg')->assertNotFound();
        $this->get('/private-storage/documents/id.jpg')->assertForbidden();
        $this->get('/storage/../private/documents/id.jpg')->assertNotFound();
    }
}

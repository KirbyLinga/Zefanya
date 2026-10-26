<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageTest extends TestCase
{
    public function test_guests_can_load_product_images_without_a_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/15/photo.jpg', 'product image');

        $this->get('/storage/products/15/photo.jpg')
            ->assertOk()
            ->assertStreamedContent('product image');
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

<?php

namespace Tests\Concerns;

use App\Models\Shared\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

trait ValidProductPublication
{
    /** Complete publication fields for tests focused on variants or inventory. */
    private function publicationDefaults(): array
    {
        return [
            'creation_key' => (string) Str::uuid(),
            'category_id' => auth('seller')->user()?->line_of_business_id ?? Category::query()->firstOrCreate(['label' => 'Home & Living'], ['icon' => 'home'])->id,
            'description' => 'Handcrafted product with carefully finished details.',
            'dimensions' => ['length' => 35, 'width' => 25, 'height' => 3],
            'images' => [UploadedFile::fake()->createWithContent('product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))],
        ];
    }
}

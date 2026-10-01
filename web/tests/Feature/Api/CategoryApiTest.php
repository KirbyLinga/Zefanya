<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/categories` — the chips the app's category row renders.
 *
 * The list is seeded by its migration (fourteen curated rows, with a comment
 * saying not to build a CRUD for them), so these tests are mostly about *which*
 * list arrives and in what order, rather than about creating data.
 *
 * The interesting property is the shape of the answer to a question the app has
 * been carrying as an open one: its own mock catalogue lists four categories
 * ('All', 'Fashion', 'Home & Living', 'Beauty') and the server has fourteen whose
 * names do not overlap. A live build shows the server's fourteen — that is the
 * answer, and it means a live category chip can never be one the server has no
 * products for, because the names come from the same table the products point at.
 */
class CategoryApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_the_curated_list_with_labels(): void
    {
        $response = $this->getJson(route('api.categories.index'));

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'label']]])
            ->assertJsonCount(14, 'data');

        $labels = collect($response->json('data'))->pluck('label');

        $this->assertContains('Home and Garden', $labels);
        $this->assertContains('Pet Supplies', $labels);
    }

    public function test_the_order_is_the_curations_own(): void
    {
        $labels = collect($this->getJson(route('api.categories.index'))->json('data'))
            ->pluck('label')
            ->all();

        // Ordered by id, which is the order the migration inserted them in — the
        // file calls itself a fixed curated list, so its order is the curation.
        // Alphabetical would be tidier and would throw that away.
        $this->assertSame('Books and Media', $labels[0]);
        $this->assertSame('Office and School Supplies', $labels[13]);
    }

    public function test_an_id_is_a_string_like_every_other_id_the_app_holds(): void
    {
        $id = $this->getJson(route('api.categories.index'))->json('data.0.id');

        $this->assertIsString($id);
    }

    public function test_it_needs_no_token(): void
    {
        $this->getJson(route('api.categories.index'))->assertOk();
    }
}

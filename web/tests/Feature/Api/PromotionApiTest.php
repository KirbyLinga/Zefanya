<?php

namespace Tests\Feature\Api;

use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/promotions — the home banner campaign slides.
 */
class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    private function slide(array $overrides = []): Promotion
    {
        return Promotion::create(array_merge([
            'sort_order' => 0,
            'is_active'  => true,
            'eyebrow'    => 'JUST ARRIVED',
            'title'      => "New This\nSeason",
            'subtitle'   => 'Fresh arrivals from Willow & Bloom',
            'layout'     => 'text_left',
            'ground'     => 'brand_rose',
            'icon'       => 'local_florist_outlined',
            'big_icon'   => null,
        ], $overrides));
    }

    public function test_returns_active_slides_in_sort_order(): void
    {
        $this->slide(['sort_order' => 1, 'eyebrow' => 'SECOND']);
        $this->slide(['sort_order' => 0, 'eyebrow' => 'FIRST']);

        $data = $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('FIRST',  $data[0]['eyebrow']);
        $this->assertSame('SECOND', $data[1]['eyebrow']);
    }

    public function test_inactive_slides_are_excluded(): void
    {
        $this->slide(['is_active' => true,  'eyebrow' => 'VISIBLE']);
        $this->slide(['is_active' => false, 'eyebrow' => 'HIDDEN']);

        $data = $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('VISIBLE', $data[0]['eyebrow']);
    }

    public function test_empty_when_no_active_slides(): void
    {
        $this->slide(['is_active' => false]);

        $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_slide_shape_matches_what_the_app_reads(): void
    {
        $this->slide([
            'eyebrow'  => 'JUST ARRIVED',
            'title'    => "New This\nSeason",
            'subtitle' => 'Fresh arrivals',
            'layout'   => 'text_left',
            'ground'   => 'brand_rose',
            'icon'     => 'local_florist_outlined',
            'big_icon' => null,
        ]);

        $slide = $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->json('data.0');

        $this->assertSame('JUST ARRIVED',          $slide['eyebrow']);
        $this->assertSame("New This\nSeason",      $slide['title']);
        $this->assertSame('Fresh arrivals',        $slide['subtitle']);
        $this->assertSame('text_left',             $slide['layout']);
        $this->assertSame('brand_rose',            $slide['ground']);
        $this->assertSame('local_florist_outlined',$slide['icon']);
        $this->assertNull($slide['big_icon']);
    }

    public function test_big_icon_is_present_when_set(): void
    {
        $this->slide(['big_icon' => 'cottage_outlined']);

        $bigIcon = $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->json('data.0.big_icon');

        $this->assertSame('cottage_outlined', $bigIcon);
    }

    public function test_endpoint_requires_no_token(): void
    {
        $this->slide();

        // Promotions are public marketing content — no sign-in required.
        $this->getJson(route('api.promotions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}

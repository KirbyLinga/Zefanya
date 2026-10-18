<?php

namespace Database\Seeders;

use App\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * Seeds the three home banner slides that match the app's mock carousel.
 *
 * The slides are keyed by eyebrow + title, so re-seeding updates rather
 * than duplicating. Order matches `mockPromoSlides` in `mock_data.dart`
 * exactly, because `buyer_shell.dart`'s `_onPromoTap` routes by index.
 *
 * The icon names are the Dart names from `mockPromoSlides` — the app's
 * lookup table resolves them to `IconData` objects. Unknown names fall
 * back to a neutral icon rather than crashing.
 */
class DemoPromotionSeeder extends Seeder
{
    private const SLIDES = [
        [
            'sort_order' => 0,
            'eyebrow'    => 'JUST ARRIVED',
            'title'      => "New This\nSeason",
            'subtitle'   => 'Fresh arrivals from Willow & Bloom',
            'layout'     => 'text_left',
            'ground'     => 'brand_rose',
            'icon'       => 'local_florist_outlined',
            'big_icon'   => null,
        ],
        [
            'sort_order' => 1,
            'eyebrow'    => 'LIMITED TIME',
            'title'      => "15% Off\nHome & Living",
            'subtitle'   => 'Code NEWHOME15 · up to ₱150 off',
            'layout'     => 'centered_hero',
            'ground'     => 'sage',
            'icon'       => 'local_offer_outlined',
            'big_icon'   => 'cottage_outlined',
        ],
        [
            'sort_order' => 2,
            'eyebrow'    => 'ALWAYS ON',
            'title'      => "Free\nShipping",
            'subtitle'   => 'On every order over ₱1,000',
            'layout'     => 'icon_split',
            'ground'     => 'terracotta',
            'icon'       => 'local_shipping_outlined',
            'big_icon'   => 'directions_bike_outlined',
        ],
    ];

    public function run(): void
    {
        foreach (self::SLIDES as $slide) {
            Promotion::updateOrCreate(
                ['eyebrow' => $slide['eyebrow'], 'title' => $slide['title']],
                $slide,
            );
        }

        $this->command?->info('DemoPromotionSeeder: 3 slides seeded.');
    }
}

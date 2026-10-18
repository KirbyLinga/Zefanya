<?php

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One carousel slide, in the shape `PromoSlide.fromJson()` on the mobile side
 * expects.
 *
 * ## Why icon names cross as strings
 *
 * Flutter's `IconData` is a runtime object — it cannot be serialised to JSON.
 * Instead, the server sends the Dart name (e.g. `'local_florist_outlined'`)
 * and the app resolves it through a lookup table. Unknown names fall back to a
 * neutral icon rather than crashing, so a typo in a future CMS does not break
 * the carousel.
 *
 * ## Newlines in the title
 *
 * The `title` column may contain a literal `\n` character so a slide author
 * can force a two-line headline without HTML. The app passes the string
 * directly to `Text()`, which respects `\n`.
 *
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'eyebrow'  => $this->eyebrow,
            'title'    => $this->title,
            'subtitle' => $this->subtitle,
            'layout'   => $this->layout,
            'ground'   => $this->ground,
            'icon'     => $this->icon,
            'big_icon' => $this->big_icon,
        ];
    }
}

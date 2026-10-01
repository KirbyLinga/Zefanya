<?php

namespace App\Http\Resources;

use App\Models\Shared\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One category, as the app's category chips need it.
 *
 * The app renders a row of chips built from a list of *names* — its own mock
 * list is `['All', 'Fashion', 'Home & Living', 'Beauty']`, four names for the
 * server's fourteen curated rows, and that mismatch is why the filter is sent as
 * a name (`CatalogQuery.category`) rather than as an id: whichever side owns the
 * names must be the side that resolves them.
 *
 * `label` is therefore the field the app reads, and `id` is carried because it is
 * the stable half of the pair — a curated list can be relabelled, and a caller
 * that has an id should not have to re-derive it from display text.
 *
 * `icon` (a lucide icon name) is deliberately absent: the app draws its category
 * tiles from its own artwork, so a shipped icon name would be a string nobody
 * reads.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
        ];
    }
}

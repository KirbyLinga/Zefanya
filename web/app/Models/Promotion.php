<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One home banner slide.
 *
 * ## What the columns mean
 *
 * - `sort_order`  — ascending display order; 0 appears first.
 * - `is_active`   — false means hidden without deletion.
 * - `eyebrow`     — small all-caps label above the headline.
 * - `title`       — the headline; may contain `\n` for a two-line break.
 * - `subtitle`    — supporting copy below the headline.
 * - `layout`      — one of `text_left`, `centered_hero`, `icon_split`.
 * - `ground`      — one of `brand_rose`, `sage`, `terracotta`.
 * - `icon`        — Dart icon name (e.g. `local_florist_outlined`).
 * - `big_icon`    — optional second icon used as a background motif.
 *
 * @property int         $id
 * @property int         $sort_order
 * @property bool        $is_active
 * @property string      $eyebrow
 * @property string      $title
 * @property string      $subtitle
 * @property string      $layout
 * @property string      $ground
 * @property string      $icon
 * @property string|null $big_icon
 */
class Promotion extends Model
{
    protected $fillable = [
        'sort_order',
        'is_active',
        'eyebrow',
        'title',
        'subtitle',
        'layout',
        'ground',
        'icon',
        'big_icon',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The active slides, in display order.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Promotion>
     */
    public static function active(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('sort_order');
    }
}

<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = ['icon', 'label'];

    // The category list is fixed, so each photo lives at public/Images/categories/{label-slug}.webp.
    public function getImageUrlAttribute(): string
    {
        return asset('Images/categories/'.Str::slug($this->label).'.webp');
    }

    public function getImageSrcsetAttribute(): string
    {
        $base = 'Images/categories/'.Str::slug($this->label);

        return asset($base.'-320.webp').' 320w, '.asset($base.'.webp').' 640w';
    }
}

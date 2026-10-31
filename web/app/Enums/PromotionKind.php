<?php

namespace App\Enums;

enum PromotionKind: string
{
    case BundlePercent = 'bundle_percent';
    case BuyXGetY = 'buy_x_get_y';

    public function label(): string
    {
        return match ($this) {
            self::BundlePercent => 'Bundle percentage',
            self::BuyXGetY => 'Buy X Get Y',
        };
    }
}

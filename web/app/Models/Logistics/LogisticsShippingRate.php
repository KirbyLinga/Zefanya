<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

/** What one Logistics company charges in one zone: the first kilo, then each kilo after it. */
class LogisticsShippingRate extends Model
{
    public const SAME_MUNICIPALITY = 'same_municipality';

    public const SAME_PROVINCE = 'same_province';

    public const CROSS_PROVINCE = 'cross_province';

    public const ZONES = [
        self::SAME_MUNICIPALITY => ['label' => 'Same city or municipality', 'help' => 'The seller and buyer are in the same city or municipality.'],
        self::SAME_PROVINCE => ['label' => 'Same province', 'help' => 'Different cities or municipalities in one province.'],
        self::CROSS_PROVINCE => ['label' => 'Another province', 'help' => 'The buyer is in another province, through an approved partner there.'],
    ];

    protected $fillable = ['logistics_provider_id', 'zone', 'first_kg_minor', 'additional_kg_minor'];

    protected function casts(): array
    {
        return ['first_kg_minor' => 'integer', 'additional_kg_minor' => 'integer'];
    }
}

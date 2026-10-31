<?php

namespace App\Services\Shared;

/**
 * What PriceResolver decided, for one variant (min equals max) or a whole
 * product's purchasable variants. All money is integer centavos.
 */
final class ResolvedPrice
{
    public function __construct(
        public readonly int $regularMinCentavos,
        public readonly int $regularMaxCentavos,
        public readonly int $finalMinCentavos,
        public readonly int $finalMaxCentavos,
        private readonly int $percentOffMin = 0,
        private readonly int $percentOffMax = 0,
        /** The sale that lowered this price; null when no sale changed it. */
        public readonly ?int $saleId = null,
    ) {}

    /** The amount a buyer pays per unit when this describes a single variant. */
    public function unitCentavos(): int
    {
        return $this->finalMinCentavos;
    }

    public function regularUnitCentavos(): int
    {
        return $this->regularMinCentavos;
    }

    public function formatted(): string
    {
        return self::range($this->finalMinCentavos, $this->finalMaxCentavos);
    }

    /** The struck-through price; null when it would read the same as the price. */
    public function formattedRegular(): ?string
    {
        $regular = self::range($this->regularMinCentavos, $this->regularMaxCentavos);

        return $regular === $this->formatted() ? null : $regular;
    }

    /** e.g. "-20%", or "Up to -20%" when only some variants get that much. */
    public function badge(): ?string
    {
        if ($this->percentOffMax <= 0) {
            return null;
        }

        return ($this->percentOffMin === $this->percentOffMax ? '' : 'Up to ')."-{$this->percentOffMax}%";
    }

    private static function range(int $minimum, int $maximum): string
    {
        $format = fn (int $centavos): string => "\u{20B1}".number_format($centavos / 100, 2);

        return $minimum === $maximum ? $format($minimum) : $format($minimum)."\u{2013}".$format($maximum);
    }
}

<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    /** Round a non-negative percentage to the nearest centavo, half up. */
    public static function percentage(int $amountMinor, int $rate, int $scale = 100): int
    {
        if ($amountMinor < 0 || $rate < 0 || $scale <= 0) {
            throw new InvalidArgumentException('Money percentages require non-negative amounts and rates, and a positive scale.');
        }

        // BCMath avoids both binary floats and overflow in the intermediate product.
        // Add half the divisor so exactly half a centavo rounds upwards.
        $weighted = bcmul((string) $amountMinor, (string) $rate, 0);
        $half = bcdiv((string) $scale, '2', 1);
        $rounded = bcdiv(bcadd($weighted, $half, 1), (string) $scale, 0);

        if (bccomp($rounded, (string) PHP_INT_MAX, 0) > 0) {
            throw new OverflowException('Rounded money exceeds the integer-centavo limit.');
        }

        return (int) $rounded;
    }
}

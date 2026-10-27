<?php

namespace App\Models;

use Brick\Math\BigDecimal;

class PercentageFormatter
{
    /** Trim display zeros without rounding or changing the stored rate. */
    public static function format(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }

        return QuantityFormatter::format((string) BigDecimal::of((string) $value)).'%';
    }
}

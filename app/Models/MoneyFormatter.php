<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class MoneyFormatter
{
    /** Presentation rounding only; never pass this result to a calculation or input. */
    public static function format(?string $value): string
    {
        if ($value === null) {
            return '—';
        }
        $decimal = (string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp);
        [$integer, $fraction] = explode('.', $decimal);
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);

        return 'EGP '.$integer.'.'.$fraction;
    }
}

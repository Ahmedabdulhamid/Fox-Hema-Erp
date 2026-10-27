<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class MoneyFormatter
{
    /** Presentation only; never pass formatted values to calculations or inputs. */
    public static function format(?string $value, string $currency = 'EGP'): string
    {
        return $value === null || $value === '' ? "\u{2014}" : $currency.' '.self::amount($value);
    }

    public static function amount(?string $value): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }

        return self::group((string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp));
    }

    /** Unit prices and valuation costs retain financially significant sub-cent precision. */
    public static function precise(?string $value, string $currency = 'EGP'): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }
        $decimal = BigDecimal::of($value)->strippedOfTrailingZeros();

        return $currency.' '.self::group((string) $decimal->toScale(max(2, $decimal->getScale())));
    }

    private static function group(string $decimal): string
    {
        [$integer, $fraction] = explode('.', $decimal);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer).'.'.$fraction;
    }
}

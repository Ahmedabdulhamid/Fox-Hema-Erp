<?php

namespace App\Modules\Sales\Services;

class SalesPricingSnapshot
{
    /** Normalize JSON object keys without changing list order, scalar types, or missing evidence. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

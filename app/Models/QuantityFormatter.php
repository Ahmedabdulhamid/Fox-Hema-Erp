<?php

namespace App\Models;

use InvalidArgumentException;

class QuantityFormatter
{
    /** Display only: keep the application's Latin digits in both English and Arabic. */
    public static function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $value = (string) $value, $parts)) {
            throw new InvalidArgumentException('Quantity display requires a plain decimal string.');
        }
        $integer = ltrim($parts[2], '0') ?: '0';
        $fraction = rtrim($parts[3] ?? '', '0');
        $sign = $parts[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction);
    }
}

<?php

namespace App\Models;

class ColorPresentation
{
    /** Normalize display only; the persisted color value is never changed. */
    public static function hex(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', $value)) {
            return null;
        }
        $hex = strtoupper(substr($value, 1));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }
}

<?php

namespace App\Modules\Products\Services;

use Mpdf\Barcode;

class VariantBarcode
{
    public function format(?string $value): ?string
    {
        if ($value === null || $value === '' || ! preg_match('/^[\x20-\x7E]{1,255}$/D', $value)) {
            return null;
        }
        if (preg_match('/^[0-9]{13}$/D', $value)
            && app(ProductVariantIdentifierGenerator::class)->checkDigit(substr($value, 0, 12)) === $value[12]) {
            return 'EAN13';
        }

        return 'C128B';
    }

    /** Render only the library's encoded modules; never normalize the stored identifier. */
    public function svg(?string $value): ?string
    {
        $format = $this->format($value);
        if ($format === null) {
            return null;
        }
        // mPDF decodes numeric HTML entities. Escape ampersands as numeric entities once to preserve literal input.
        $encoded = $format === 'C128B' ? str_replace('&', '&#x26;', $value) : $value;
        $data = (new Barcode)->getBarcodeArray($encoded, $format);
        $left = max(11, (int) $data['lightmL']);
        $right = max(11, (int) $data['lightmR']);
        $width = $left + $data['maxw'] + $right;
        $height = 80;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="'.e($value).'" viewBox="0 0 '.$width.' '.$height.'" width="'.($width * 0.33).'mm" height="26.4mm" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="white"/>';
        $position = $left;
        foreach ($data['bcode'] as $bar) {
            if ($bar['t']) {
                $svg .= '<rect x="'.$position.'" y="0" width="'.$bar['w'].'" height="'.$height.'" fill="black"/>';
            }
            $position += $bar['w'];
        }

        return $svg.'</svg>';
    }
}

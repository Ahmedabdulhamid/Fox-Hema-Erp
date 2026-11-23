<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductImport;
use Normalizer;
use Ramsey\Uuid\Uuid;

class ProductImportProductIdentifiers
{
    public function normalizeName(string $name): string
    {
        $name = Normalizer::normalize($name, Normalizer::FORM_KC);

        return mb_convert_case(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $name)), MB_CASE_FOLD, 'UTF-8');
    }

    /** A fixed source and random operation UUID reproduce identity without relying on worksheet positions. */
    public function productKey(ProductImport $import, string $name): string
    {
        $identity = Uuid::uuid5($import->uuid, 'employee-product:'.$this->normalizeName($name))->toString();

        return 'PRD-'.strtoupper(str_replace('-', '', $identity));
    }

    public function variantKey(string $productKey, string $color, string $size): string
    {
        return 'v-'.substr(hash('sha256', json_encode([$productKey, $color, $size], JSON_THROW_ON_ERROR)), 0, 48);
    }
}

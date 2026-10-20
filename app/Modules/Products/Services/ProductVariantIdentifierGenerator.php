<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Str;
use RuntimeException;

class ProductVariantIdentifierGenerator
{
    public function assign(ProductVariant $variant): void
    {
        if (filled($variant->sku) && filled($variant->barcode)) {
            return;
        }
        $connection = $variant->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new RuntimeException('Automatic variant identifiers require a transaction.');
        }
        if ($connection->getDriverName() === 'mysql') {
            $lock = 'variant-identifiers:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 32);
            $result = $connection->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$lock]);
            if ((int) $result->acquired !== 1) {
                throw new RuntimeException('Variant identifier allocation is busy. Please retry.');
            }
            $release = fn () => $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            $connection->afterCommit($release);
            $connection->afterRollBack($release);
        }
        if (blank($variant->sku)) {
            $base = $this->skuBase($variant);
            $candidate = $base;
            $suffix = 1;
            while ($variant->newQuery()->where('sku', $candidate)->lockForUpdate()->first(['id']) !== null) {
                $candidate = $base.'-'.strtoupper(base_convert((string) ++$suffix, 10, 36));
            }
            $variant->sku = $candidate;
        }
        if (blank($variant->barcode)) {
            $attempt = 0;
            do {
                $number = (string) (hexdec(substr(hash('sha256', $variant->sku.':'.$attempt++), 0, 12)) % 10000000000);
                $digits = '20'.str_pad($number, 10, '0', STR_PAD_LEFT);
                $candidate = $digits.$this->checkDigit($digits);
            } while ($variant->newQuery()->where('barcode', $candidate)->lockForUpdate()->first(['id']) !== null);
            $variant->barcode = $candidate;
        }
    }

    public function checkDigit(string $digits): string
    {
        if (! preg_match('/^[0-9]{12}$/', $digits)) {
            throw new RuntimeException('EAN-13 check digits require twelve numeric digits.');
        }
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $digits[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function skuBase(ProductVariant $variant): string
    {
        $product = $variant->product()->with('brand')->firstOrFail();
        $brand = $product->brand?->name;
        $words = $this->words($product->name);
        $brandWords = $this->words($brand ?? '');
        if ($brandWords !== [] && array_slice($words, 0, count($brandWords)) === $brandWords) {
            $words = array_slice($words, count($brandWords));
        }
        $productCode = count($words) > 1 ? substr($words[0], 0, 1).$this->shortCode(end($words), 2, 'P') : $this->shortCode(implode('', $words), 4, 'P', $product->name);
        $parts = [];
        if (filled($brand)) {
            $parts[] = $this->shortCode($brand, 3, 'B');
        }
        $parts[] = $productCode;
        if ($variant->color_id !== null) {
            $parts[] = $this->shortCode($variant->color()->firstOrFail()->name, 3, 'C');
        }
        if ($variant->size_id !== null) {
            $size = $variant->size()->firstOrFail()->name;
            $parts[] = substr(implode('', $this->words($size)), 0, 4) ?: $this->shortCode($size, 4, 'S');
        }

        return implode('-', $parts);
    }

    private function words(string $value): array
    {
        return preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii($value)), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function shortCode(string $value, int $length, string $prefix, ?string $fallback = null): string
    {
        $normalized = implode('', $this->words($value));
        if ($normalized === '') {
            return $prefix.strtoupper(substr(hash('sha256', $fallback ?? $value), 0, 4));
        }
        $consonants = preg_replace('/[AEIOU]/', '', $normalized);

        $code = $consonants ?: $normalized;

        return strlen($code) > $length ? substr($code, 0, $length - 1).substr($code, -1) : $code;
    }
}

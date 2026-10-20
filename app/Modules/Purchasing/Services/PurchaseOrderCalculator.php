<?php

namespace App\Modules\Purchasing\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class PurchaseOrderCalculator
{
    public function decimal(mixed $value, string $field, int $precision = 20, int $scale = 4): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d+(\.\d+)?$/D', (string) $value)) {
            $this->invalid($field, 'Use a nonnegative plain decimal string.');
        }
        try {
            $decimal = BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary);
        } catch (MathException $exception) {
            $this->invalid($field, 'The decimal has too many fractional digits.');
        }
        if ($decimal->isGreaterThanOrEqualTo(BigDecimal::of('1'.str_repeat('0', $precision - $scale)))) {
            $this->invalid($field, 'The amount exceeds database precision.');
        }

        return (string) $decimal;
    }

    /** Explicit line amounts; unit prices exclude tax. Round the extended subtotal once, half up. */
    public function line(array $data, string $prefix = ''): array
    {
        $quantity = $this->decimal($data['quantity'] ?? null, $prefix.'quantity', 18, 4);
        if (BigDecimal::of($quantity)->isZero()) {
            $this->invalid($prefix.'quantity', 'Ordered quantity must be positive.');
        }
        $price = $this->decimal($data['unit_price'] ?? null, $prefix.'unit_price', 18, 6);
        $subtotal = $this->decimal((string) BigDecimal::of($quantity)->multipliedBy($price)->toScale(4, RoundingMode::HalfUp), $prefix.'subtotal');
        $discount = $this->decimal($data['discount_amount'] ?? '0', $prefix.'discount_amount');
        $tax = $this->decimal($data['tax_amount'] ?? '0', $prefix.'tax_amount');
        if (BigDecimal::of($discount)->isGreaterThan($subtotal)) {
            $this->invalid($prefix.'discount_amount', 'Discount cannot exceed the line subtotal.');
        }
        $total = $this->decimal((string) BigDecimal::of($subtotal)->minus($discount)->plus($tax), $prefix.'line_total');

        return ['quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $subtotal,
            'discount_amount' => $discount, 'tax_amount' => $tax, 'line_total' => $total];
    }

    public function totals(array $lines): array
    {
        $totals = [];
        foreach (['subtotal' => 'subtotal', 'discount_amount' => 'discount_amount', 'tax_amount' => 'tax_amount', 'total_amount' => 'line_total'] as $header => $lineField) {
            $sum = BigDecimal::zero();
            foreach ($lines as $line) {
                $sum = $sum->plus($line[$lineField]);
            }
            $totals[$header] = $this->decimal((string) $sum, $header);
        }

        return $totals;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}

<?php

namespace App\Modules\Sales\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class SalesCreditCalculator
{
    /**
     * Cumulative net, discount and tax entitlements use invoice snapshots and four-decimal half-up rounding.
     * Deriving subtotal from net plus discount prevents a one-tick discount exceeding a tiny subtotal.
     * The final quantity receives each exact remaining component, including invoice residuals.
     */
    public function allocate(array $source, string $previous, string $quantity): array
    {
        $total = BigDecimal::of($source['quantity']);
        $before = BigDecimal::of($previous);
        $after = $before->plus($quantity);
        if (! $total->isPositive() || $before->isNegative() || ! BigDecimal::of($quantity)->isPositive() || $after->isGreaterThan($total)) {
            $this->invalid();
        }
        $net = BigDecimal::of($source['subtotal'])->minus($source['discount_amount']);
        if ($net->isNegative() || ! $net->plus($source['tax_amount'])->isEqualTo($source['line_total'])) {
            $this->invalid();
        }
        $result = [];
        foreach (['net' => $net, 'discount_amount' => $source['discount_amount'], 'tax_amount' => $source['tax_amount']] as $field => $value) {
            $amount = BigDecimal::of($value);
            if ($amount->isNegative()) {
                $this->invalid();
            }
            $result[$field] = (string) $amount->multipliedBy($after)->dividedBy($total, 4, RoundingMode::HalfUp)
                ->minus($amount->multipliedBy($before)->dividedBy($total, 4, RoundingMode::HalfUp));
        }
        $result['subtotal'] = (string) BigDecimal::of($result['net'])->plus($result['discount_amount']);
        $result['line_total'] = (string) BigDecimal::of($result['net'])->plus($result['tax_amount']);
        unset($result['net']);
        if (! BigDecimal::of($source['subtotal'])->minus($source['discount_amount'])->plus($source['tax_amount'])->isEqualTo($source['line_total'])
            || BigDecimal::of($source['discount_amount'])->isGreaterThan($source['subtotal'])
            || BigDecimal::of($result['discount_amount'])->isGreaterThan($result['subtotal']) || BigDecimal::of($result['line_total'])->isNegative()) {
            $this->invalid();
        }

        return $result;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['record' => __('credits.inconsistent')]);
    }
}

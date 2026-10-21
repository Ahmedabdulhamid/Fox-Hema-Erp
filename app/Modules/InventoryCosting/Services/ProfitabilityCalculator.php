<?php

namespace App\Modules\InventoryCosting\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ProfitabilityCalculator
{
    /** Form previews accept incomplete input without manufacturing a price or cost. */
    public function preview(mixed $price, mixed $cost): array
    {
        if (! is_string($price) || ! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $price)) {
            return [];
        }
        $cost = is_string($cost) && preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $cost) ? $cost : null;

        return $this->calculate($price, $cost);
    }

    /** @return array{revenue: string, cost: ?string, profit: ?string, margin: ?string, markup: ?string} */
    public function calculate(string $revenue, ?string $cost): array
    {
        $revenue = BigDecimal::of($revenue)->toScale(4, RoundingMode::HalfUp);
        $cost = $cost === null ? null : BigDecimal::of($cost)->toScale(4, RoundingMode::HalfUp);
        $profit = $cost === null ? null : $revenue->minus($cost);

        return ['revenue' => (string) $revenue, 'cost' => $cost === null ? null : (string) $cost,
            'profit' => $profit === null ? null : (string) $profit,
            'margin' => $profit !== null && ! $revenue->isZero() ? (string) $profit->multipliedBy(100)->dividedBy($revenue, 2, RoundingMode::HalfUp) : null,
            'markup' => $profit !== null && ! $cost->isZero() ? (string) $profit->multipliedBy(100)->dividedBy($cost, 2, RoundingMode::HalfUp) : null];
    }
}

<?php

namespace App\Modules\Payments\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class PaymentMoney
{
    public function normalize(mixed $amount, string $currency = 'EGP'): string
    {
        if ($currency !== 'EGP') {
            throw ValidationException::withMessages(['currency' => __('Only EGP payments are supported.')]);
        }
        if ((! is_string($amount) && ! is_int($amount)) || ! preg_match('/^\d+(\.\d+)?$/D', (string) $amount)) {
            $this->invalid();
        }
        try {
            $value = BigDecimal::of($amount)->toScale(4, RoundingMode::Unnecessary);
        } catch (MathException $exception) {
            $this->invalid();
        }
        if (! $value->isPositive() || $value->isGreaterThanOrEqualTo('10000000000000000')) {
            $this->invalid();
        }

        return (string) $value;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['amount' => __('Use a positive plain decimal within DECIMAL(20,4); rounding inputs is forbidden.')]);
    }
}

<?php

namespace App\Modules\Purchasing\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseSettlementTerms
{
    /** @return array{settlement_terms: ?string, planned_immediate_amount: ?string} */
    public function validate(array $data, string $total): array
    {
        $terms = $data['settlement_terms'] ?? null;
        $amount = $data['planned_immediate_amount'] ?? null;
        Validator::make($data, ['settlement_terms' => ['nullable', 'in:cash,credit,mixed']])->validate();
        if ($terms === null && ($amount === null || $amount === '')) {
            return ['settlement_terms' => null, 'planned_immediate_amount' => null];
        }
        if ($terms === null) {
            throw ValidationException::withMessages(['settlement_terms' => __('Select commercial settlement terms.')]);
        }
        $amount = app(PurchaseOrderCalculator::class)->decimal($amount, 'planned_immediate_amount', 20, 4);
        $planned = BigDecimal::of($amount);
        $bill = BigDecimal::of($total);
        if ($planned->isNegative() || $planned->isGreaterThan($bill)
            || ($terms === 'cash' && ! $planned->isEqualTo($bill))
            || ($terms === 'credit' && ! $planned->isZero())
            || ($terms === 'mixed' && (! $planned->isPositive() || ! $planned->isLessThan($bill)))) {
            throw ValidationException::withMessages(['planned_immediate_amount' => __('Cash requires the full total, credit requires zero, and mixed requires an amount between zero and the bill total.')]);
        }
        if (in_array($terms, ['credit', 'mixed'], true)) {
            Validator::make($data, ['due_date' => ['required', 'date', 'after_or_equal:bill_date']])->validate();
        }

        return ['settlement_terms' => $terms, 'planned_immediate_amount' => $amount];
    }
}

<?php

namespace App\Modules\Payments\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerSettlementTerms
{
    /** @return array{settlement_terms: ?string, planned_immediate_amount: ?string, due_date: ?string} */
    public function validate(array $data, string $total, string $invoiceDate): array
    {
        $terms = $data['settlement_terms'] ?? null;
        $amount = $data['planned_immediate_amount'] ?? null;
        $due = $data['due_date'] ?? null;
        Validator::make(['terms' => $terms, 'due' => $due, 'invoice_date' => $invoiceDate], [
            'terms' => ['nullable', 'in:cash,credit,mixed'], 'due' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
        ], [], __('payments.validation_attributes'))->validate();
        if ($terms === null && ($amount === null || $amount === '')) {
            return ['settlement_terms' => null, 'planned_immediate_amount' => null, 'due_date' => $due];
        }
        $zero = (is_string($amount) || is_int($amount)) && preg_match('/^0+(\.0+)?$/D', (string) $amount);
        $amount = $zero ? '0.0000' : app(PaymentMoney::class)->normalize($amount);
        $planned = BigDecimal::of($amount);
        $bill = BigDecimal::of($total);
        if ($terms === null || ($terms === 'cash' && ! $planned->isEqualTo($bill))
            || ($terms === 'credit' && ! $planned->isZero())
            || ($terms === 'mixed' && (! $planned->isPositive() || ! $planned->isLessThan($bill)))) {
            throw ValidationException::withMessages(['planned_immediate_amount' => __('Cash requires the full total, credit requires zero, and mixed requires an amount between zero and the invoice total.')]);
        }
        if (in_array($terms, ['credit', 'mixed'], true) && $due === null) {
            throw ValidationException::withMessages(['due_date' => __('Credit and mixed terms require a due date.')]);
        }

        return ['settlement_terms' => $terms, 'planned_immediate_amount' => $amount, 'due_date' => $due];
    }
}

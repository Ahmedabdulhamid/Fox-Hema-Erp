<?php

namespace App\Modules\Taxes\Services;

use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class CommercialLineCalculator
{
    public function __construct(private readonly PurchaseOrderCalculator $calculator) {}

    public function line(ProductVariant $variant, array $input, string $prefix = '', string $usage = 'sales'): array
    {
        foreach (['tax_id', 'tax_rate', 'discount_ids', 'commercial_snapshot'] as $field) {
            if (array_key_exists($field, $input)) {
                $this->invalid($prefix.$field, 'This field is controlled by configured document pricing.');
            }
        }
        $base = $this->calculator->line(['quantity' => $input['quantity'] ?? null, 'unit_price' => $input['unit_price'] ?? null], $prefix);
        $definitions = app(CommercialDefinitions::class);
        $discount = $definitions->discount($variant->product, $usage, $input, $prefix);
        $discountAmount = '0.0000';
        if ($discount !== null) {
            if ($discount->type !== 'percentage' || $discount->is_stackable) {
                $this->invalid($prefix.'discount_id', 'Fixed or stackable discounts require an agreed calculation policy.');
            }
            $this->percentage($discount->value, $prefix.'discount_id');
            $discountAmount = (string) BigDecimal::of($base['subtotal'])->multipliedBy($discount->value)->dividedBy('100', 4, RoundingMode::HalfUp);
        }
        $taxable = (string) BigDecimal::of($base['subtotal'])->minus($discountAmount);
        $taxAmount = BigDecimal::zero();
        $taxes = [];
        $definitions = $definitions->taxes($variant->product, $usage, $input, $prefix);
        foreach ($definitions as $tax) {
            if ($tax->type !== 'percentage' || $tax->price_inclusion !== 'excluded') {
                $this->invalid($prefix.'tax_ids', 'Fixed or included taxes require an agreed calculation policy.');
            }
            $this->percentage($tax->rate, $prefix.'tax_ids');
            $amount = (string) BigDecimal::of($taxable)->multipliedBy($tax->rate)->dividedBy('100', 4, RoundingMode::HalfUp);
            $taxAmount = $taxAmount->plus($amount);
            $taxes[] = ['id' => $tax->id, 'code' => $tax->code, 'name' => $tax->name, 'type' => $tax->type,
                'rate' => $tax->rate, 'price_inclusion' => $tax->price_inclusion, 'basis' => $taxable, 'amount' => $amount];
        }
        $result = $this->calculator->line(['quantity' => $base['quantity'], 'unit_price' => $base['unit_price'],
            'discount_amount' => $discountAmount, 'tax_amount' => (string) $taxAmount], $prefix);
        foreach (['discount_amount', 'tax_amount'] as $field) {
            if (array_key_exists($field, $input) && $this->calculator->decimal($input[$field], $prefix.$field) !== $result[$field]) {
                $this->invalid($prefix.$field, 'Amounts must match configured document pricing. Reload and save the draft.');
            }
        }

        return $result + ['commercial_snapshot' => ['version' => 1, 'policy' => 'excluded-percentage-after-discount-half-up-4',
            'unit_price' => $result['unit_price'], 'quantity' => $result['quantity'], 'subtotal' => $result['subtotal'],
            'taxable_amount' => $taxable, 'discount' => $discount === null ? null : ['id' => $discount->id, 'code' => $discount->code,
                'name' => $discount->name, 'type' => $discount->type, 'value' => $discount->value, 'amount' => $discountAmount],
            'taxes' => $taxes, 'discount_amount' => $result['discount_amount'], 'tax_amount' => $result['tax_amount'],
            'line_total' => $result['line_total']]];
    }

    private function percentage(string $value, string $field): void
    {
        $value = $this->calculator->decimal($value, $field);
        if (BigDecimal::of($value)->isGreaterThan('100')) {
            $this->invalid($field, 'Configured percentages must be between zero and 100.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}

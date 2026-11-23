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

    public function line(ProductVariant $variant, array $input, string $prefix = '', string $usage = 'sales', bool $includeTaxUsage = false, int|string|null $supplierId = null): array
    {
        foreach (['tax_id', 'tax_rate', 'discount_ids', 'commercial_snapshot'] as $field) {
            if (array_key_exists($field, $input)) {
                $this->invalid($prefix.$field, 'This field is controlled by configured document pricing.');
            }
        }
        $base = $this->calculator->line(['quantity' => $input['quantity'] ?? null, 'unit_price' => $input['unit_price'] ?? null], $prefix);
        $definitions = app(CommercialDefinitions::class);
        $discount = $definitions->discount($variant->product, $usage, $input, $prefix, $supplierId);
        $discountAmount = '0.0000';
        $usesFixedAdjustment = false;
        if ($discount !== null) {
            if ($discount->is_stackable) {
                $this->invalid($prefix.'discount_id', 'Stackable discounts require an agreed calculation policy.');
            }
            if ($discount->type === 'fixed') {
                $discountAmount = $this->calculator->decimal($discount->value, $prefix.'discount_id');
                $usesFixedAdjustment = true;
            } elseif ($discount->type === 'percentage') {
                $this->percentage($discount->value, $prefix.'discount_id');
                $discountAmount = (string) BigDecimal::of($base['subtotal'])->multipliedBy($discount->value)->dividedBy('100', 4, RoundingMode::HalfUp);
            } else {
                $this->invalid($prefix.'discount_id', 'The configured discount type is not supported.');
            }
            if (BigDecimal::of($discountAmount)->isGreaterThan($base['subtotal'])) {
                $this->invalid($prefix.'discount_id', 'Discount cannot exceed the line subtotal.');
            }
        }
        $taxable = (string) BigDecimal::of($base['subtotal'])->minus($discountAmount);
        $taxAmount = BigDecimal::zero();
        $recoverableTaxAmount = BigDecimal::zero();
        $capitalizableTaxAmount = BigDecimal::zero();
        $taxes = [];
        $definitions = $definitions->taxes($variant->product, $usage, $input, $prefix);
        foreach ($definitions as $tax) {
            if ($tax->price_inclusion !== 'excluded') {
                $this->invalid($prefix.'tax_ids', 'Included taxes require an agreed calculation policy.');
            }
            if ($tax->type === 'fixed') {
                $amount = $this->calculator->decimal($tax->rate, $prefix.'tax_ids');
                $usesFixedAdjustment = true;
            } elseif ($tax->type === 'percentage') {
                $this->percentage($tax->rate, $prefix.'tax_ids');
                $amount = (string) BigDecimal::of($taxable)->multipliedBy($tax->rate)->dividedBy('100', 4, RoundingMode::HalfUp);
            } else {
                $this->invalid($prefix.'tax_ids', 'The configured tax type is not supported.');
            }
            $taxAmount = $taxAmount->plus($amount);
            if ($includeTaxUsage) {
                if (! in_array($tax->purchase_treatment, ['recoverable', 'capitalizable'], true)
                    && ! BigDecimal::of($amount)->isZero()) {
                    $this->invalid($prefix.'tax_ids', 'Purchase tax treatment must be configured before this tax can be used.');
                }
                if ($tax->purchase_treatment === 'recoverable') {
                    $recoverableTaxAmount = $recoverableTaxAmount->plus($amount);
                } else {
                    $capitalizableTaxAmount = $capitalizableTaxAmount->plus($amount);
                }
            }
            $taxes[] = ['id' => $tax->id, 'code' => $tax->code, 'name' => $tax->name, 'type' => $tax->type,
                'rate' => $tax->rate, 'price_inclusion' => $tax->price_inclusion,
                'basis' => $tax->type === 'percentage' ? $taxable : null, 'amount' => $amount];
            if ($includeTaxUsage) {
                $taxes[array_key_last($taxes)]['usage'] = $tax->usage;
                $taxes[array_key_last($taxes)]['purchase_treatment'] = $tax->purchase_treatment;
            }
        }
        $result = $this->calculator->line(['quantity' => $base['quantity'], 'unit_price' => $base['unit_price'],
            'discount_amount' => $discountAmount, 'tax_amount' => (string) $taxAmount], $prefix);
        foreach (['discount_amount', 'tax_amount'] as $field) {
            if (array_key_exists($field, $input) && $this->calculator->decimal($input[$field], $prefix.$field) !== $result[$field]) {
                $this->invalid($prefix.$field, 'Amounts must match configured document pricing. Reload and save the draft.');
            }
        }

        $policy = $usesFixedAdjustment ? 'excluded-fixed-line-amounts-and-percentages-after-discount-half-up-4'
            : 'excluded-percentage-after-discount-half-up-4';

        $snapshot = ['version' => 1, 'policy' => $policy,
            'unit_price' => $result['unit_price'], 'quantity' => $result['quantity'], 'subtotal' => $result['subtotal'],
            'taxable_amount' => $taxable, 'discount' => $discount === null ? null : ['id' => $discount->id, 'code' => $discount->code,
                'name' => $discount->name, 'type' => $discount->type, 'value' => $discount->value, 'amount' => $discountAmount],
            'taxes' => $taxes, 'discount_amount' => $result['discount_amount'], 'tax_amount' => $result['tax_amount'],
            'line_total' => $result['line_total']];
        if ($includeTaxUsage) {
            $inventoryAcquisitionAmount = BigDecimal::of($taxable)->plus($capitalizableTaxAmount)->toScale(4);
            $snapshot += ['recoverable_tax_amount' => (string) $recoverableTaxAmount->toScale(4),
                'capitalizable_tax_amount' => (string) $capitalizableTaxAmount->toScale(4),
                'supplier_payable_amount' => $result['line_total'],
                'inventory_acquisition_amount' => (string) $inventoryAcquisitionAmount,
                'inventory_acquisition_unit_cost' => $this->calculator->decimal(
                    (string) $inventoryAcquisitionAmount->dividedBy($result['quantity'], 6, RoundingMode::HalfUp),
                    $prefix.'inventory_acquisition_unit_cost', 18, 6)];
            if ($discount !== null) {
                $snapshot['discount']['supplier_id'] = $discount->supplier_id;
            }
        }

        return $result + ['commercial_snapshot' => $snapshot];
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

<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\CommercialLineCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseLinePricing
{
    public function taxes(Product $product): Collection
    {
        return app(CommercialDefinitions::class)->taxes($product, 'purchases', []);
    }

    /** Calculate the purchase-order payable and persist its commercial policy snapshot. */
    public function line(ProductVariant $variant, array $input, string $prefix = '', int|string|null $supplierId = null): array
    {
        if (array_key_exists('supplier_discount_id', $input)) {
            throw ValidationException::withMessages([$prefix.'discount_id' => __('Choose an eligible configured supplier discount.')]);
        }
        // Customer product promotions are never purchase defaults.
        $input['discount_id'] ??= null;
        $line = app(CommercialLineCalculator::class)->line($variant, $input, $prefix, 'purchases',
            includeTaxUsage: true, supplierId: $supplierId);
        $snapshot = &$line['commercial_snapshot'];
        $snapshot['policy'] = 'purchase-excluded-line-fixed-amounts-and-percentage-after-discount-half-up-4';
        $snapshot['inventory_cost_policy'] = 'net-purchase-amount-plus-capitalizable-tax';

        return $line;
    }

    /** Validate the persisted commercial policy used by confirmation and billing. */
    public function assertPostable(array $line, string $prefix = ''): void
    {
        $snapshot = $line['commercial_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            throw ValidationException::withMessages([$prefix.'commercial_snapshot' => __('Purchase pricing evidence is missing.')]);
        }
        if (($snapshot['discount']['type'] ?? null) === 'fixed'
            && BigDecimal::of($snapshot['discount']['value'] ?? '0')->isGreaterThan($snapshot['subtotal'] ?? '0')) {
            throw ValidationException::withMessages([$prefix.'discount_id' => __('Discount cannot exceed the line subtotal.')]);
        }
        foreach ($snapshot['taxes'] ?? [] as $tax) {
            if (! is_array($tax) || ($tax['price_inclusion'] ?? 'excluded') !== 'excluded') {
                throw ValidationException::withMessages([$prefix.'tax_ids' => __('Purchase tax policy is unsupported.')]);
            }
            if (! in_array($tax['purchase_treatment'] ?? null, ['recoverable', 'capitalizable'], true)
                && ! BigDecimal::of($tax['amount'] ?? '0')->isZero()) {
                throw ValidationException::withMessages([$prefix.'tax_ids' => __('Purchase tax treatment is missing.')]);
            }
        }
    }

    /**
     * Allocate the confirmed PO's line-level fixed amounts across partial receipts or bills.
     *
     * @param  array<string, mixed>  $sourceSnapshot
     * @return array<string, mixed>
     */
    public function allocate(array $sourceSnapshot, string $quantity, string $unitPrice, string $orderedQuantity, string $alreadyAllocatedQuantity, string $prefix = ''): array
    {
        $calculator = app(PurchaseOrderCalculator::class);
        $quantity = $calculator->decimal($quantity, $prefix.'quantity', 18, 4);
        $orderedQuantity = $calculator->decimal($orderedQuantity, $prefix.'ordered_quantity', 18, 4);
        $alreadyAllocatedQuantity = $calculator->decimal($alreadyAllocatedQuantity, $prefix.'allocated_quantity', 18, 4);
        if (BigDecimal::of($quantity)->isZero()
            || BigDecimal::of($alreadyAllocatedQuantity)->plus($quantity)->isGreaterThan($orderedQuantity)) {
            throw ValidationException::withMessages([$prefix.'quantity' => __('Commercial amounts cannot be allocated beyond the ordered quantity.')]);
        }
        $base = $calculator->line(['quantity' => $quantity, 'unit_price' => $unitPrice], $prefix);
        $discount = $sourceSnapshot['discount'] ?? null;
        $discountAmount = '0.0000';
        if (is_array($discount)) {
            if (($discount['type'] ?? null) === 'fixed') {
                $discountAmount = $this->allocateFixed((string) ($discount['value'] ?? ''), $quantity, $orderedQuantity,
                    $alreadyAllocatedQuantity, $prefix.'discount_id');
            } elseif (($discount['type'] ?? null) === 'percentage' && isset($discount['value'])) {
                $this->assertPercentage((string) $discount['value'], $prefix.'discount_id');
                $discountAmount = (string) BigDecimal::of($base['subtotal'])->multipliedBy($discount['value'])
                    ->dividedBy('100', 4, RoundingMode::HalfUp);
            } else {
                throw ValidationException::withMessages([$prefix.'discount_id' => __('The confirmed purchase discount policy is invalid.')]);
            }
        }
        if (BigDecimal::of($discountAmount)->isGreaterThan($base['subtotal'])) {
            throw ValidationException::withMessages([$prefix.'discount_id' => __('Discount cannot exceed the line subtotal.')]);
        }
        $taxableAmount = BigDecimal::of($base['subtotal'])->minus($discountAmount)->toScale(4);
        $taxes = [];
        $taxAmount = BigDecimal::zero();
        $recoverableTaxAmount = BigDecimal::zero();
        $capitalizableTaxAmount = BigDecimal::zero();
        foreach ($sourceSnapshot['taxes'] ?? [] as $tax) {
            if (! is_array($tax) || ($tax['price_inclusion'] ?? 'excluded') !== 'excluded') {
                throw ValidationException::withMessages([$prefix.'tax_ids' => __('The confirmed purchase tax policy is invalid or unsupported.')]);
            }
            if (($tax['type'] ?? null) === 'fixed') {
                $amount = $this->allocateFixed((string) ($tax['rate'] ?? ''), $quantity, $orderedQuantity,
                    $alreadyAllocatedQuantity, $prefix.'tax_ids');
            } elseif (($tax['type'] ?? null) === 'percentage' && isset($tax['rate'])) {
                $this->assertPercentage((string) $tax['rate'], $prefix.'tax_ids');
                $amount = (string) $taxableAmount->multipliedBy($tax['rate'])->dividedBy('100', 4, RoundingMode::HalfUp);
            } else {
                throw ValidationException::withMessages([$prefix.'tax_ids' => __('The confirmed purchase tax type is invalid.')]);
            }
            $treatment = $tax['purchase_treatment'] ?? null;
            if (! in_array($treatment, ['recoverable', 'capitalizable'], true)
                && ! BigDecimal::of($amount)->isZero()) {
                throw ValidationException::withMessages([$prefix.'tax_ids' => __('Purchase tax treatment is missing from the confirmed pricing snapshot.')]);
            }
            $taxAmount = $taxAmount->plus($amount);
            if ($treatment === 'recoverable') {
                $recoverableTaxAmount = $recoverableTaxAmount->plus($amount);
            } elseif ($treatment === 'capitalizable') {
                $capitalizableTaxAmount = $capitalizableTaxAmount->plus($amount);
            }
            $taxes[] = array_replace($tax, ['basis' => ($tax['type'] ?? null) === 'percentage' ? (string) $taxableAmount : null,
                'amount' => $amount]);
        }
        $line = $calculator->line(['quantity' => $quantity, 'unit_price' => $base['unit_price'],
            'discount_amount' => $discountAmount, 'tax_amount' => (string) $taxAmount], $prefix);
        $inventoryAcquisitionAmount = $taxableAmount->plus($capitalizableTaxAmount)->toScale(4);
        $inventoryAcquisitionUnitCost = $inventoryAcquisitionAmount->isEqualTo($base['subtotal'])
            ? $calculator->decimal($unitPrice, $prefix.'inventory_acquisition_unit_cost', 18, 6)
            : $calculator->decimal(
                (string) $inventoryAcquisitionAmount->dividedBy($quantity, 6, RoundingMode::HalfUp),
                $prefix.'inventory_acquisition_unit_cost', 18, 6);

        return $line + ['commercial_snapshot' => [
            'version' => 1,
            'allocation_policy' => 'fixed-line-amount-prorated-by-cumulative-quantity-half-up-4',
            'quantity' => $quantity,
            'unit_price' => $line['unit_price'],
            'subtotal' => $line['subtotal'],
            'taxable_amount' => (string) $taxableAmount,
            'discount' => $discount === null ? null : array_replace($discount, ['amount' => $discountAmount]),
            'taxes' => $taxes,
            'discount_amount' => $discountAmount,
            'recoverable_tax_amount' => (string) $recoverableTaxAmount->toScale(4),
            'capitalizable_tax_amount' => (string) $capitalizableTaxAmount->toScale(4),
            'tax_amount' => $line['tax_amount'],
            'supplier_payable_amount' => $line['line_total'],
            'inventory_acquisition_amount' => (string) $inventoryAcquisitionAmount,
            'inventory_acquisition_unit_cost' => $inventoryAcquisitionUnitCost,
            'line_total' => $line['line_total'],
        ]];
    }

    private function allocateFixed(string $total, string $quantity, string $orderedQuantity, string $alreadyAllocatedQuantity, string $field): string
    {
        $calculator = app(PurchaseOrderCalculator::class);
        $total = $calculator->decimal($total, $field);
        $previousQuantity = BigDecimal::of($alreadyAllocatedQuantity);
        $currentQuantity = $previousQuantity->plus($quantity);
        $previousAmount = $previousQuantity->isZero()
            ? BigDecimal::zero()
            : ($previousQuantity->isEqualTo($orderedQuantity)
                ? BigDecimal::of($total)
                : BigDecimal::of($total)->multipliedBy($previousQuantity)->dividedBy($orderedQuantity, 4, RoundingMode::HalfUp));
        $cumulativeAmount = $currentQuantity->isEqualTo($orderedQuantity)
            ? BigDecimal::of($total)
            : BigDecimal::of($total)->multipliedBy($currentQuantity)->dividedBy($orderedQuantity, 4, RoundingMode::HalfUp);

        return $calculator->decimal((string) $cumulativeAmount->minus($previousAmount), $field);
    }

    private function assertPercentage(string $value, string $field): void
    {
        $value = app(PurchaseOrderCalculator::class)->decimal($value, $field);
        if (BigDecimal::of($value)->isGreaterThan('100')) {
            throw ValidationException::withMessages([$field => __('Configured percentages must be between zero and 100.')]);
        }
    }
}

<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\CommercialLineCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseLinePricing
{
    public function taxes(Product $product): Collection
    {
        return app(CommercialDefinitions::class)->taxes($product, 'purchases', []);
    }

    /** Draft arithmetic never changes receipt unit costs or valuation. */
    public function line(ProductVariant $variant, array $input, string $prefix = ''): array
    {
        if (array_key_exists('supplier_discount_id', $input)) {
            throw ValidationException::withMessages([$prefix.'discount_id' => __('Choose an eligible configured supplier discount.')]);
        }
        // Customer product promotions are never purchase defaults.
        $input['discount_id'] ??= null;
        $line = app(CommercialLineCalculator::class)->line($variant, $input, $prefix, 'purchases');
        $snapshot = &$line['commercial_snapshot'];
        $snapshot['policy'] = 'purchase-excluded-percentage-draft-only';
        $snapshot['inventory_cost_policy'] = 'existing-receipt-unit-cost-and-category-valuation-unchanged';
        foreach ($snapshot['taxes'] as &$tax) {
            $tax['usage'] = app(CommercialDefinitions::class)->eligible('taxes', 'purchases')->whereKey($tax['id'])->value('usage');
        }

        return $line;
    }

    public function assertPostable(array $line, string $prefix = ''): void
    {
        $errors = [];
        foreach (['discount_amount' => 'discount_id', 'tax_amount' => 'tax_ids'] as $amount => $field) {
            $snapshot = $line['commercial_snapshot'];
            $configuredNonzero = $field === 'discount_id'
                ? ! BigDecimal::of($snapshot['discount']['value'] ?? '0')->isZero()
                : collect($snapshot['taxes'])->contains(fn (array $tax): bool => ! BigDecimal::of($tax['rate'])->isZero());
            if ($configuredNonzero || ! BigDecimal::of($line[$amount])->isZero()) {
                $errors[$prefix.$field] = __('This selection is calculated in the draft. Confirmation requires an approved supplier-discount, tax-recovery and inventory-cost policy. Choose no adjustment or a zero-valued definition.');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

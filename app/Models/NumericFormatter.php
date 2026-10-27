<?php

namespace App\Models;

use Brick\Math\BigDecimal;

class NumericFormatter
{
    /** An explicit presentation map keeps identifiers, dates, and descriptive fields untouched. */
    public static function kind(string $path): ?string
    {
        $name = substr(strrchr('.'.$path, '.'), 1);
        if (preg_match('/(?:^|_)(?:id|uuid|sku|barcode|code|number|phone)$/', $name)) {
            return null;
        }
        if (preg_match('/(?:^|_)(?:quantity|qty)(?:_|$)/', $name)
            || in_array($name, ['ordered', 'remaining', 'previously_received', 'summary_delivered', 'summary_posted', 'summary_claimed', 'summary_remaining'], true)) {
            return 'quantity';
        }
        if (in_array($name, ['unit_price', 'unit_cost', 'valuation_unit_cost', 'purchase_unit_cost', 'avg_cost', 'cost_price', 'sale_price', 'variants_min_sale_price', 'variants_max_sale_price', 'variants_min_cost_price', 'price_difference'], true)) {
            return 'precise';
        }
        if (in_array($name, ['amount', 'subtotal', 'total_amount', 'line_total', 'discount_amount', 'tax_amount', 'total_purchase_value', 'purchase_value', 'total_cost', 'purchase_price_variance', 'original_cost', 'carrying_value', 'return_variance', 'loss_value', 'provisional_carrying_value', 'planned_immediate_amount', 'ledger_balance', 'available_advance', 'refunded_amount', 'remaining_amount', 'outstanding', 'paid', 'credited', 'revenue', 'cost', 'profit', 'balance'], true)) {
            return 'money';
        }
        if (in_array($name, ['margin', 'markup', 'percentage'], true)) {
            return 'percentage';
        }

        return null;
    }

    public static function format(mixed $value, string $kind, string $currency = 'EGP'): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }
        if (! is_numeric($value)) {
            return (string) $value;
        }
        $decimal = (string) BigDecimal::of((string) $value);

        return match ($kind) {
            'money' => MoneyFormatter::format($decimal, $currency),
            'precise' => MoneyFormatter::precise($decimal, $currency),
            'percentage' => PercentageFormatter::format($decimal),
            default => QuantityFormatter::format($decimal),
        };
    }

    public static function definition(mixed $value, string $type): string
    {
        return self::format($value, $type === 'percentage' ? 'percentage' : 'precise');
    }
}

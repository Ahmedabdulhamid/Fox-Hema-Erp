<?php

namespace Tests\Unit;

use App\Modules\Sales\Services\SalesCreditCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesCreditCalculatorTest extends TestCase
{
    public function test_fractional_cumulative_allocations_conserve_every_component(): void
    {
        $source = ['quantity' => '3.0000', 'subtotal' => '30.3704', 'discount_amount' => '1.1111', 'tax_amount' => '2.2222', 'line_total' => '31.4815'];
        $sum = ['subtotal' => BigDecimal::zero(), 'discount_amount' => BigDecimal::zero(), 'tax_amount' => BigDecimal::zero(), 'line_total' => BigDecimal::zero()];
        $previous = BigDecimal::zero();
        foreach (['0.0001', '0.3332', '0.6667', '1.9999', '0.0001'] as $quantity) {
            $line = app(SalesCreditCalculator::class)->allocate($source, (string) $previous, $quantity);
            foreach ($sum as $key => $amount) {
                $sum[$key] = $amount->plus($line[$key]);
            }
            $previous = $previous->plus($quantity);
        }
        foreach ($sum as $key => $amount) {
            $this->assertTrue($amount->isEqualTo($source[$key]), $key);
        }
    }

    public function test_tiny_discounted_amounts_never_produce_negative_net_credit(): void
    {
        $source = ['quantity' => '3', 'subtotal' => '0.0002', 'discount_amount' => '0.0001', 'tax_amount' => '0.0001', 'line_total' => '0.0002'];
        $sum = BigDecimal::zero();
        foreach (['0', '1', '2'] as $previous) {
            $line = app(SalesCreditCalculator::class)->allocate($source, $previous, '1');
            $this->assertFalse(BigDecimal::of($line['line_total'])->isNegative());
            $this->assertFalse(BigDecimal::of($line['discount_amount'])->isGreaterThan($line['subtotal']));
            $sum = $sum->plus($line['line_total']);
        }
        $this->assertTrue($sum->isEqualTo('0.0002'));
    }

    public function test_large_precise_amounts_never_pass_through_binary_float(): void
    {
        $source = ['quantity' => '2', 'subtotal' => '999999999999999.1234', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'line_total' => '999999999999999.1234'];
        $first = app(SalesCreditCalculator::class)->allocate($source, '0', '1');
        $last = app(SalesCreditCalculator::class)->allocate($source, '1', '1');
        $this->assertSame('499999999999999.5617', $first['line_total']);
        $this->assertTrue(BigDecimal::of($first['line_total'])->plus($last['line_total'])->isEqualTo($source['line_total']));
    }

    public function test_inconsistent_source_totals_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SalesCreditCalculator::class)->allocate(['quantity' => '1', 'subtotal' => '10', 'discount_amount' => '1', 'tax_amount' => '2', 'line_total' => '12'], '0', '1');
    }

    public function test_over_crediting_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SalesCreditCalculator::class)->allocate(['quantity' => '1', 'subtotal' => '10', 'discount_amount' => '1', 'tax_amount' => '2', 'line_total' => '11'], '0.9999', '0.0002');
    }
}

<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderCalculatorTest extends TestCase
{
    public function test_large_decimal_math_keeps_exact_digits_and_rounds_half_up(): void
    {
        $calculator = new PurchaseOrderCalculator;
        $line = $calculator->line(['quantity' => '9999.9999', 'unit_price' => '999999999999.999999']);
        $this->assertSame('9999999899999999.9900', $line['subtotal']);
        $this->assertSame($line['subtotal'], $line['line_total']);
        $this->assertSame('9999999999999999.9999', $calculator->decimal('9999999999999999.9999', 'amount'));
        $this->assertSame('0.0001', $calculator->line(['quantity' => '0.0001', 'unit_price' => '0.500000'])['subtotal']);
    }

    public function test_rounding_inputs_is_forbidden_and_overflow_is_rejected(): void
    {
        $calculator = new PurchaseOrderCalculator;
        foreach (['0.00001', '10000000000000000', '-0.01', '1e2', 'NaN', 0.1] as $input) {
            try {
                $calculator->decimal($input, 'amount');
                $this->fail('Invalid decimal input was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }
    }
}

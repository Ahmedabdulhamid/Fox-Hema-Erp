<?php

namespace Tests\Unit;

use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfitabilityCalculatorTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_decimal_profit_margin_and_markup(string $revenue, ?string $cost, ?string $profit, ?string $margin, ?string $markup): void
    {
        $actual = (new ProfitabilityCalculator)->calculate($revenue, $cost);
        $this->assertSame($profit, $actual['profit']);
        $this->assertSame($margin, $actual['margin']);
        $this->assertSame($markup, $actual['markup']);
    }

    public static function amounts(): array
    {
        return [
            'example' => ['2000', '1500', '500.0000', '25.00', '33.33'],
            'unknown' => ['2000', null, null, null, null],
            'free' => ['0', '1500', '-1500.0000', null, '-100.00'],
            'zero cost' => ['2000', '0', '2000.0000', '100.00', null],
            'loss' => ['1000', '1500', '-500.0000', '-50.00', '-33.33'],
            'refund' => ['-2000', '-1500', '-500.0000', '25.00', '33.33'],
            'large exact amounts' => ['99999999999999.9999', '99999999999999.9998', '0.0001', '0.00', '0.00'],
        ];
    }

    public function test_incomplete_form_inputs_do_not_manufacture_a_cost_or_price(): void
    {
        $calculator = new ProfitabilityCalculator;
        $this->assertSame([], $calculator->preview('', '15'));
        $this->assertNull($calculator->preview('20', '')['profit']);
        $this->assertSame('5.0000', $calculator->preview('20', '15')['profit']);
    }
}

<?php

namespace Tests\Unit;

use App\Models\MoneyFormatter;
use App\Models\QuantityFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityFormatterTest extends TestCase
{
    public static function quantities(): array
    {
        return [
            ['20.0000', '20'], ['50.0000', '50'], ['12.5000', '12.5'], ['10.2500', '10.25'],
            ['3.1250', '3.125'], ['0.0000', '0'], ['-5.5000', '-5.5'], ['0.0001', '0.0001'],
            [null, '—'], [0, '0'], ['-0.0000', '0'], ['00012.5000', '12.5'],
            ['999999999999999999999999.1234567890123456789000', '999999999999999999999999.1234567890123456789'],
            ['0.00000000000000000001', '0.00000000000000000001'],
        ];
    }

    #[DataProvider('quantities')]
    public function test_display_preserves_exact_meaningful_digits(string|int|null $input, string $expected): void
    {
        $this->assertSame($expected, QuantityFormatter::format($input));
    }

    public function test_invalid_decimal_notation_is_not_silently_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuantityFormatter::format('1e-4');
    }

    public function test_money_formatting_is_separate_and_does_not_mutate_input(): void
    {
        $value = '12345678901234.123456';
        $this->assertSame('EGP 12,345,678,901,234.12', MoneyFormatter::format($value));
        $this->assertSame('12345678901234.123456', $value);
        $this->assertSame('EGP 200.00', MoneyFormatter::format('200.000000'));
        $this->assertSame('EGP -5.50', MoneyFormatter::format('-5.5000'));
        $this->assertSame('—', MoneyFormatter::format(null));
    }

    public function test_binary_floats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QuantityFormatter::format(12.5);
    }
}

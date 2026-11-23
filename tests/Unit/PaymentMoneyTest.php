<?php

namespace Tests\Unit;

use App\Modules\Payments\Services\PaymentFingerprint;
use App\Modules\Payments\Services\PaymentMoney;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentMoneyTest extends TestCase
{
    public function test_exact_precision_and_invalid_amounts(): void
    {
        $money = new PaymentMoney;
        $this->assertSame('8000.0000', $money->normalize('8000'));
        $this->assertSame('0.0001', $money->normalize('0.0001'));
        $this->assertSame('9999999999999999.9999', $money->normalize('9999999999999999.9999'));
        foreach ([0, '-1', '0.00001', '10000000000000000', 0.1, 10.0, '1e2', ' 1', '+1', null, [], 'NaN'] as $amount) {
            try {
                $money->normalize($amount);
                $this->fail('Invalid payment accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }
    }

    public function test_unsupported_currencies_fail(): void
    {
        $this->expectException(ValidationException::class);
        (new PaymentMoney)->normalize('1', 'USD');
    }

    public function test_fingerprints_preserve_ownership_types_and_list_order(): void
    {
        $f = new PaymentFingerprint;
        $a = $f->make('payments', 'create', ['b' => ['d' => '2', 'c' => '1'], 'a' => ['1', '2']]);
        $this->assertSame($a, $f->make('payments', 'create', ['a' => ['1', '2'], 'b' => ['c' => '1', 'd' => '2']]));
        $this->assertNotSame($a, $f->make('sales', 'create', ['a' => ['1', '2'], 'b' => ['c' => '1', 'd' => '2']]));
        $this->assertNotSame($a, $f->make('payments', 'post', ['a' => ['1', '2'], 'b' => ['c' => '1', 'd' => '2']]));
        $this->assertNotSame($a, $f->make('payments', 'create', ['a' => ['2', '1'], 'b' => ['c' => '1', 'd' => '2']]));
        $this->expectException(\InvalidArgumentException::class);
        $f->make('payments', 'create', ['amount' => 1.1]);
    }
}

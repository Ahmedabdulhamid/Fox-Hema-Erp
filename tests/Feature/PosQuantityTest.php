<?php

namespace Tests\Feature;

use App\Modules\POS\Rules\WholeQuantity;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PosQuantityTest extends TestCase
{
    #[TestWith([1, true])]
    #[TestWith(['2', true])]
    #[TestWith(['10.0000', true])]
    #[TestWith(['1.0', true])]
    #[TestWith(['0.001', false])]
    #[TestWith(['0.0002', false])]
    #[TestWith(['1.5', false])]
    #[TestWith(['2.25', false])]
    #[TestWith(['1.00001', false])]
    #[TestWith([1.5, false])]
    #[TestWith([0, false])]
    #[TestWith([-1, false])]
    #[TestWith([true, false])]
    #[TestWith(['1e0', false])]
    public function test_pos_quantity_requires_positive_whole_units(mixed $quantity, bool $valid): void
    {
        $validator = Validator::make(['quantity' => $quantity], ['quantity' => ['required', new WholeQuantity]]);
        $this->assertSame($valid, $validator->passes());
    }
}

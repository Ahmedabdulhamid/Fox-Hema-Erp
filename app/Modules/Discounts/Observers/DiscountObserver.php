<?php

namespace App\Modules\Discounts\Observers;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Services\ShortCodeGenerator;

class DiscountObserver
{
    public function creating(Discount $discount): void
    {
        if (blank($discount->code)) {
            $discount->code = app(ShortCodeGenerator::class)->next('DIS', $discount);
        }
    }
}

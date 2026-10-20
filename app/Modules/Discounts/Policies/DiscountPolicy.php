<?php

namespace App\Modules\Discounts\Policies;

use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Taxes\Policies\TaxPolicy;

class DiscountPolicy extends TaxPolicy
{
    protected string $module = 'discounts';

    protected string $manager = DiscountManager::class;
}

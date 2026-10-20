<?php

namespace App\Modules\Discounts\Services;

use App\Modules\Taxes\Services\TaxReferenceChecker;

class DiscountReferenceChecker extends TaxReferenceChecker
{
    protected string $column = 'discount_id';

    protected string $pivot = 'discount_products';
}

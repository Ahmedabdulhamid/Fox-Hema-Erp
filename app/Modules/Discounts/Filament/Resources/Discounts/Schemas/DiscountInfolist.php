<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Schemas;

use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxInfolist;

class DiscountInfolist extends TaxInfolist
{
    protected static string $kind = 'discounts';
}

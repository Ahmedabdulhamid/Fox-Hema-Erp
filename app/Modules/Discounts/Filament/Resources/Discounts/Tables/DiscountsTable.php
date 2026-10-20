<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Tables;

use App\Modules\Taxes\Filament\Resources\Taxes\Tables\TaxesTable;

class DiscountsTable extends TaxesTable
{
    protected static string $kind = 'discounts';
}

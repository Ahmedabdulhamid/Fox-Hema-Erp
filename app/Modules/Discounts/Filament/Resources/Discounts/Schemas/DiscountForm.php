<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Schemas;

use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('Discount definition'))->columns(2)->columnSpanFull()->schema(TaxForm::fields('discounts'))]);
    }
}
